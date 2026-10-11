<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A blog schema that uses every relation type the viewer draws. The tests and `composer serve` share it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table): void {
            $table->id();
            $table->char('code', 2)->unique();
            $table->string('name');
        });

        // The workbench app already has Laravel's own users table; the tests start empty.
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->foreignId('country_id')->nullable()->after('id')->constrained()->nullOnDelete();
            });
        } else {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->timestamps();
            });
        }

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        // No index starts with role_id: the composite primary key leads with user_id.
        Schema::create('role_user', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('role_id');
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->primary(['user_id', 'role_id']);
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
        });

        // category_id has no foreign key on purpose.
        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('category_id')->nullable()->index();
            $table->string('title')->comment('Shown in lists and feeds');
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // No model declares the user relation behind this foreign key.
        Schema::create('comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('commentable');
            $table->text('body');
            $table->boolean('approved')->default(false);
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('taggables', function (Blueprint $table): void {
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->morphs('taggable');
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('event');
        });

        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table): void {
                $table->id();
                $table->string('queue')->index();
            });
        }
    }
};
