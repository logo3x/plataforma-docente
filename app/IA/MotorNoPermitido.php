<?php

namespace App\IA;

use RuntimeException;

/** La tarea procesa datos de estudiantes y el motor es en la nube sin autorización (1.5.3). */
final class MotorNoPermitido extends RuntimeException {}
