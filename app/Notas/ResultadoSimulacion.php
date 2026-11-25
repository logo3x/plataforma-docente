<?php

namespace App\Notas;

final readonly class ResultadoSimulacion
{
    /**
     * @param  ?string  $notaNecesaria  nota mínima en lo pendiente para aprobar (precisión completa)
     * @param  bool  $alMargen  pasa o pierde por menos del margen configurado (3.7.3)
     */
    public function __construct(
        public ClasificacionCierre $clasificacion,
        public ?string $notaNecesaria,
        public bool $alMargen,
    ) {}
}
