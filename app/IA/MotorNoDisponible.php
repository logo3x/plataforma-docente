<?php

namespace App\IA;

use RuntimeException;

/** El motor configurado no responde. Nunca se intenta con otro motor (1.5.4). */
final class MotorNoDisponible extends RuntimeException {}
