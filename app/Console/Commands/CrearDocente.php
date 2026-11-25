<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/** Alta de docentes por consola: la aplicación no tiene registro público. */
class CrearDocente extends Command
{
    protected $signature = 'docente:crear {email?} {--nombre=}';

    protected $description = 'Crea la cuenta de un docente';

    public function handle(): int
    {
        $email = $this->argument('email') ?? text('Correo institucional', required: true);
        $nombre = $this->option('nombre') ?? text('Nombre completo', required: true);
        $clave = password('Contraseña (mínimo 12 caracteres)', required: true);

        $v = Validator::make(
            ['email' => $email, 'name' => $nombre, 'password' => $clave],
            [
                'email' => ['required', 'email', 'unique:users,email'],
                'name' => ['required', 'string', 'max:150'],
                'password' => ['required', Password::min(12)->letters()->numbers()],
            ],
        );

        if ($v->fails()) {
            foreach ($v->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        User::query()->create(['email' => $email, 'name' => $nombre, 'password' => $clave]);
        $this->info("Docente {$nombre} creado. Inicie sesión y active el segundo factor en «Seguridad».");

        return self::SUCCESS;
    }
}
