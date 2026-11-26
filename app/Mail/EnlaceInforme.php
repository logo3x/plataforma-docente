<?php

namespace App\Mail;

use App\Models\Devolucion;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo con el enlace personal al informe (6.9.3). No lleva la nota ni el detalle: un correo se
 * reenvía; el informe queda detrás del enlace firmado, que vence y se puede retirar (6.9.4).
 */
class EnlaceInforme extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Devolucion $devolucion,
        public User $docente,
        public string $enlace,
        public ?string $mensaje = null,
    ) {}

    public function envelope(): Envelope
    {
        $a = $this->devolucion->actividad;

        return new Envelope(
            replyTo: [new Address($this->docente->email, $this->docente->name)],
            subject: "Su informe de «{$a->nombre}» — {$a->componente->division->dictado->materia->nombre}",
        );
    }

    public function content(): Content
    {
        $a = $this->devolucion->actividad;

        return new Content(
            html: 'emails.enlace-informe',
            text: 'emails.enlace-informe-texto',
            with: [
                'estudiante' => $this->devolucion->estudiante,
                'actividad' => $a,
                'materia' => $a->componente->division->dictado->materia,
                'vence' => $this->devolucion->enlace_vence_en,
            ],
        );
    }
}
