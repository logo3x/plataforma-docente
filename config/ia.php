<?php

return [
    /*
     * 1.5.3 — Preferencia por lo local cuando hay datos de estudiantes. En false (por defecto),
     * cualquier tarea marcada con datos de estudiantes se niega a usar un motor en la nube.
     */
    'permitir_nube_con_datos_estudiantes' => env('IA_PERMITIR_NUBE_CON_DATOS_ESTUDIANTES', false),
];
