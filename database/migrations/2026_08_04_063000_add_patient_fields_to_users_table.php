<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            // إضافة حقول إضافية للمريض بعد عمود 'phone'
            $table->text('address')->nullable()->after('phone');
            $table->date('birth_date')->nullable()->after('address');
            $table->string('avatar')->nullable()->after('birth_date');
            $table->string('emergency_contact')->nullable()->after('avatar');
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'address',
                'birth_date',
                'avatar',
                'emergency_contact'
            ]);
        });
    }
};
