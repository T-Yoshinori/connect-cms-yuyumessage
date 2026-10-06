<?php

namespace App\Plugins\User\Yuyumessage\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Private, authenticated chunk storage: never write decrypted files to public storage. */
class MessageAttachmentService
{
    private const CHUNK_BYTES = 1048576;

    public function validate(array $files): array
    {
        $settings = (new MessageOperationsService())->settings();
        abort_unless(count($files) <= $settings['attachment_count'], 422);
        $types = [
            'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
            'gif' => ['image/gif'], 'webp' => ['image/webp'], 'heic' => ['image/heic', 'image/heif'],
            'mp4' => ['video/mp4'], 'm4v' => ['video/mp4'], 'mov' => ['video/quicktime'], 'webm' => ['video/webm'],
            'pdf' => ['application/pdf'], 'txt' => ['text/plain'], 'csv' => ['text/plain', 'text/csv'],
            'zip' => ['application/zip', 'application/x-zip-compressed'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
            'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2'],
            'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2'],
            'ppt' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/CDFV2'],
        ];
        $total = 0;
        $validated = [];
        foreach ($files as $file) {
            abort_unless($file instanceof UploadedFile && $file->isValid(), 422);
            $extension = strtolower($file->getClientOriginalExtension());
            $mime = $file->getMimeType();
            abort_unless(isset($types[$extension]) && in_array($extension, $settings['allowed_extensions'], true)
                && in_array($mime, $types[$extension], true), 422);
            $kind = strpos($mime, 'image/') === 0 ? 'image' : (strpos($mime, 'video/') === 0 ? 'video' : 'file');
            abort_unless($settings[$kind . '_enabled'], 422);
            $bytes = $file->getSize();
            abort_unless($bytes > 0 && $bytes <= $settings[$kind . '_max_mb'] * 1048576, 422);
            if ($kind === 'image' && $extension !== 'heic') {
                abort_unless(@getimagesize($file->getPathname()) !== false, 422);
            }
            $total += $bytes;
            $name = preg_replace('/[\\x00-\\x1f\\x7f\\\\\/]/u', '_', $file->getClientOriginalName());
            $validated[] = ['file' => $file, 'name' => mb_substr($name ?: 'attachment.' . $extension, 0, 180),
                'mime_type' => $mime, 'byte_size' => $bytes, 'kind' => $kind];
        }
        abort_unless($total <= $settings['attachment_total_mb'] * 1048576, 422);
        return $validated;
    }

    public function store(int $messageId, array $item, array &$written): void
    {
        $key = bin2hex(random_bytes(24));
        $path = $this->path($key);
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException('Cannot create private attachment storage.');
        }
        $written[] = $key;
        $input = fopen($item['file']->getPathname(), 'rb');
        $output = fopen($path, 'xb');
        if (!$input || !$output) {
            if (is_resource($input)) {
                fclose($input);
            }
            throw new \RuntimeException('Cannot open attachment storage.');
        }
        $chunks = [];
        $offset = 0;
        try {
            chmod($path, 0600);
            while (!feof($input)) {
                $plain = fread($input, self::CHUNK_BYTES);
                if ($plain === false) {
                    throw new \RuntimeException('Cannot read attachment.');
                }
                if ($plain === '') {
                    break;
                }
                $encrypted = Crypt::encryptString($plain);
                $length = strlen($encrypted);
                $writtenBytes = 0;
                while ($writtenBytes < $length) {
                    $count = fwrite($output, substr($encrypted, $writtenBytes));
                    if (!$count) {
                        throw new \RuntimeException('Cannot write attachment.');
                    }
                    $writtenBytes += $count;
                }
                $chunks[] = [$offset, $length, strlen($plain)];
                $offset += $length;
            }
        } finally {
            fclose($input);
            fclose($output);
        }
        if (array_sum(array_column($chunks, 2)) !== (int) $item['byte_size']) {
            throw new \RuntimeException('Attachment size changed while storing.');
        }
        DB::table('yuyu_message_attachments')->insert([
            'message_id' => $messageId, 'storage_key' => $key,
            'metadata_ciphertext' => (new MessageCipher())->encrypt(['name' => $item['name'], 'kind' => $item['kind'], 'chunks' => $chunks]),
            'mime_type' => $item['mime_type'], 'byte_size' => $item['byte_size'], 'state' => 'ready',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function removeFiles(array $keys): void
    {
        foreach ($keys as $key) {
            $path = $this->path($key);
            if (is_file($path) && !unlink($path)) {
                throw new \RuntimeException('Cannot remove private attachment.');
            }
        }
    }

    public function present($row): array
    {
        $metadata = (new MessageCipher())->decrypt($row->metadata_ciphertext);
        return ['id' => (int) $row->id, 'name' => $metadata['name'], 'kind' => $metadata['kind'],
            'mime_type' => $row->mime_type, 'byte_size' => (int) $row->byte_size];
    }

    public function response(int $conversationId, int $attachmentId, $request): StreamedResponse
    {
        $row = (new MessageConversationService())->authorized($conversationId, function ($conversation, $participant) use ($attachmentId) {
            $row = DB::table('yuyu_message_attachments as a')->join('yuyu_message_messages as m', 'a.message_id', '=', 'm.id')
                ->where('a.id', $attachmentId)->where('a.state', 'ready')->whereNull('m.deleted_at')
                ->where('m.conversation_id', $conversation->id)->where('m.sequence', '>=', $participant->join_sequence)
                ->select('a.*')->first();
            abort_unless($row, 404);
            return $row;
        });
        $metadata = (new MessageCipher())->decrypt($row->metadata_ciphertext);
        $size = (int) $row->byte_size;
        $start = 0;
        $end = $size - 1;
        $status = 200;
        $headers = ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
            'Accept-Ranges' => 'bytes', 'Content-Type' => $row->mime_type];
        $range = $request->header('Range');
        if ($range !== null) {
            if (!preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $matches) || ($matches[1] === '' && $matches[2] === '')) {
                return new StreamedResponse(null, 416, $headers + ['Content-Range' => 'bytes */' . $size]);
            }
            if ($matches[1] === '') {
                $start = max(0, $size - (int) $matches[2]);
            } else {
                $start = (int) $matches[1];
                $end = $matches[2] === '' ? $end : min($end, (int) $matches[2]);
            }
            if ($start > $end || ($matches[1] === '' && (int) $matches[2] === 0)) {
                return new StreamedResponse(null, 416, $headers + ['Content-Range' => 'bytes */' . $size]);
            }
            $status = 206;
            $headers['Content-Range'] = 'bytes ' . $start . '-' . $end . '/' . $size;
        }
        $headers['Content-Length'] = (string) ($end - $start + 1);
        $inline = !$request->boolean('download') && in_array($metadata['kind'], ['image', 'video'], true);
        $headers['Content-Disposition'] = HeaderUtils::makeDisposition($inline ? 'inline' : 'attachment', $metadata['name'], 'attachment');
        $handle = fopen($this->path($row->storage_key), 'rb');
        abort_unless($handle !== false, 404);
        return new StreamedResponse(function () use ($handle, $metadata, $start, $end) {
            try {
                $plainOffset = 0;
                foreach ($metadata['chunks'] as $chunk) {
                    if ($plainOffset > $end) {
                        break;
                    }
                    if ($plainOffset + $chunk[2] > $start) {
                        fseek($handle, $chunk[0]);
                        $ciphertext = '';
                        while (strlen($ciphertext) < $chunk[1]) {
                            $part = fread($handle, $chunk[1] - strlen($ciphertext));
                            if ($part === false || $part === '') {
                                throw new \RuntimeException('Incomplete encrypted attachment.');
                            }
                            $ciphertext .= $part;
                        }
                        $plain = Crypt::decryptString($ciphertext);
                        echo substr($plain, max(0, $start - $plainOffset), min($chunk[2] - max(0, $start - $plainOffset), $end - max($start, $plainOffset) + 1));
                    }
                    $plainOffset += $chunk[2];
                }
            } finally {
                fclose($handle);
            }
        }, $status, $headers);
    }

    private function path(string $key): string
    {
        if (!preg_match('/^[a-f0-9]{48}$/D', $key)) {
            throw new \RuntimeException('Invalid attachment storage key.');
        }
        return storage_path('app/yuyu-message/attachments/' . $key);
    }
}

