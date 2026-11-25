<?php

namespace App\IA;

use RuntimeException;

/** El modelo declinó la solicitud; no hay contenido utilizable. */
final class RespuestaRechazada extends RuntimeException {}
