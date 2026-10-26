<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        try {
            foreach (User::getAll() as $user) {
                if (($user->getDetails()['home_dir'] ?? null) === '/var/www') {
                    $user->setDetails(['home_dir' => $user->project()->homeDirPath()]);
                    $user->save();
                }
            }
        } catch (Exception $e) {
        }
    }

    public function down(): void {}
};
