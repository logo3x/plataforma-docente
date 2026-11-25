<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResultadosNavegadorRequest extends FormRequest
{
    /** Solo el docente dueño de la materia puede calificar la entrega. */
    public function authorize(): bool
    {
        $entrega = $this->route('entrega');
        $materia = $entrega->actividad->componente->division->dictado->materia;

        return $this->user() !== null && $materia->user_id === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'resultados' => ['required', 'array', 'min:1'],
            'resultados.*.item' => ['required', 'integer'],
            'resultados.*.verificaciones' => ['required', 'array', 'min:1'],
            'resultados.*.verificaciones.*' => ['boolean'],
            'resultados.*.casos' => ['sometimes', 'array', 'max:50'],
        ];
    }
}
