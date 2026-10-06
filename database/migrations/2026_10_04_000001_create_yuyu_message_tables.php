<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateYuyuMessageTables extends Migration
{
    public function up()
    {
        Schema::create('yuyu_message_conversations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('kind', 16);
            $table->string('direct_key', 64)->nullable()->unique();
            $table->unsignedBigInteger('group_id')->nullable()->unique();
            $table->text('title_ciphertext')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->timestamps();
        });
        Schema::create('yuyu_message_participants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('user_id')->index();
            $table->boolean('is_manager')->default(false);
            $table->unsignedBigInteger('join_sequence');
            $table->unsignedBigInteger('last_read_sequence')->default(0);
            $table->unsignedBigInteger('source_membership_id')->nullable();
            $table->dateTime('joined_at');
            $table->dateTime('left_at')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'user_id'], 'ymsg_participant_unique');
            $table->foreign('conversation_id', 'ymsg_participant_conv_fk')
                ->references('id')->on('yuyu_message_conversations')->onDelete('cascade');
        });
        Schema::create('yuyu_message_membership_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('participant_id');
            $table->unsignedBigInteger('join_sequence');
            $table->unsignedBigInteger('leave_sequence')->nullable();
            $table->dateTime('joined_at');
            $table->dateTime('left_at')->nullable();
            $table->foreign('participant_id', 'ymsg_history_participant_fk')
                ->references('id')->on('yuyu_message_participants')->onDelete('cascade');
        });
        Schema::create('yuyu_message_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('sequence');
            $table->unsignedBigInteger('sender_id');
            $table->string('client_token', 64);
            $table->longText('payload_ciphertext')->nullable();
            $table->dateTime('edited_at')->nullable();
            $table->dateTime('deleted_at')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'sequence'], 'ymsg_sequence_unique');
            $table->unique(['conversation_id', 'sender_id', 'client_token'], 'ymsg_send_unique');
            $table->foreign('conversation_id', 'ymsg_message_conv_fk')
                ->references('id')->on('yuyu_message_conversations')->onDelete('cascade');
        });
        Schema::create('yuyu_message_attachments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('message_id');
            $table->string('storage_key', 191)->unique();
            $table->text('metadata_ciphertext');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('byte_size');
            $table->string('state', 16)->default('pending');
            $table->timestamps();
            $table->foreign('message_id', 'ymsg_attachment_message_fk')
                ->references('id')->on('yuyu_message_messages')->onDelete('cascade');
        });
        Schema::create('yuyu_message_likes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('message_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
            $table->unique(['message_id', 'user_id'], 'ymsg_like_unique');
            $table->foreign('message_id', 'ymsg_like_message_fk')
                ->references('id')->on('yuyu_message_messages')->onDelete('cascade');
        });
        Schema::create('yuyu_message_notification_preferences', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->unique();
            $table->boolean('email_enabled')->default(true);
            $table->dateTime('last_emailed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('yuyu_message_notification_states', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('participant_id')->unique();
            $table->unsignedBigInteger('notified_sequence')->default(0);
            $table->dateTime('first_unnotified_at')->nullable();
            $table->dateTime('claimed_at')->nullable();
            $table->timestamps();
            $table->foreign('participant_id', 'ymsg_notice_participant_fk')
                ->references('id')->on('yuyu_message_participants')->onDelete('cascade');
        });
    }

    public function down()
    {
        foreach ([
            'notification_states', 'notification_preferences', 'likes', 'attachments',
            'messages', 'membership_history', 'participants', 'conversations',
        ] as $name) {
            Schema::dropIfExists('yuyu_message_' . $name);
        }
    }
}

