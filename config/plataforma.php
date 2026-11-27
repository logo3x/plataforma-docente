<?php

return [
    /*
     * Nombre de la institución que usa la plataforma. Sale en la portada de acceso, en los informes de los
     * estudiantes y en la ficha de lo que se publica. Vacío: no se muestra ninguno.
     */
    'institucion' => env('INSTITUCION', ''),

    /*
     * Correo de devolución (6.9). Si se indica un dominio (por ejemplo «ejemplo.edu.co»), los enlaces
     * solo se envían a direcciones de ese dominio: el informe es personal y no debe salir a
     * correos personales que nadie verificó.
     */
    'correo_dominio' => env('CORREO_DOMINIO_INSTITUCIONAL'),

    /* Con true los correos se envían en segundo plano (requiere «php artisan queue:work»). */
    'correo_en_cola' => (bool) env('CORREO_EN_COLA', false),
];
