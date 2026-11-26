<?php

namespace App\Classroom;

use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Lo que la plataforma usa de la API de Classroom y de Drive, con los errores redactados en español. */
final class ClienteClassroom
{
    private const API = 'https://classroom.googleapis.com/v1';

    public function __construct(private readonly ConexionGoogle $conexion) {}

    /** @return array<int, array{id: string, nombre: string, seccion: ?string}> */
    public function cursos(User $u): array
    {
        return array_map(fn ($c) => ['id' => $c['id'], 'nombre' => $c['name'], 'seccion' => $c['section'] ?? null],
            $this->todo($u, '/courses', 'courses', ['teacherId' => 'me', 'courseStates' => 'ACTIVE']));
    }

    /** @return array<int, array{id: string, email: ?string, nombre: string}> */
    public function alumnos(User $u, string $curso): array
    {
        return array_map(fn ($s) => ['id' => $s['userId'], 'email' => isset($s['profile']['emailAddress']) ? mb_strtolower($s['profile']['emailAddress']) : null,
            'nombre' => $s['profile']['name']['fullName'] ?? ''], $this->todo($u, "/courses/{$curso}/students", 'students'));
    }

    /** @return array<int, array{id: string, titulo: string, puntos: ?float, propia: bool, enlace: ?string}> */
    public function tareas(User $u, string $curso): array
    {
        return array_map(fn ($w) => $this->tarea($w), $this->todo($u, "/courses/{$curso}/courseWork", 'courseWork', ['courseWorkStates' => 'PUBLISHED']));
    }

    /** Crea la tarea desde la plataforma: así Classroom acepta después que esta aplicación le ponga las notas. */
    public function crearTarea(User $u, string $curso, string $titulo, ?string $descripcion, float $puntos, bool $publicar): array
    {
        $r = $this->http($u)->post(self::API."/courses/{$curso}/courseWork", array_filter([
            'title' => $titulo, 'description' => $descripcion, 'workType' => 'ASSIGNMENT', 'maxPoints' => $puntos,
            'state' => $publicar ? 'PUBLISHED' : 'DRAFT',
        ], fn ($v) => $v !== null));

        return $this->tarea($this->revisar($r));
    }

    /** @return array<int, array{id: string, alumno: string, estado: string, tarde: bool, archivos: array<int, array{id: string, nombre: string}>, nota: ?float}> */
    public function entregas(User $u, string $curso, string $tarea): array
    {
        return array_map(fn ($s) => [
            'id' => $s['id'], 'alumno' => $s['userId'], 'estado' => $s['state'] ?? 'NEW', 'tarde' => (bool) ($s['late'] ?? false),
            'archivos' => array_values(array_filter(array_map(fn ($a) => isset($a['driveFile']['id']) ? ['id' => $a['driveFile']['id'], 'nombre' => $a['driveFile']['title'] ?? 'archivo'] : null,
                $s['assignmentSubmission']['attachments'] ?? []))),
            'nota' => $s['assignedGrade'] ?? $s['draftGrade'] ?? null,
        ], $this->todo($u, "/courses/{$curso}/courseWork/{$tarea}/studentSubmissions", 'studentSubmissions'));
    }

    /** @return array{contenido: string, mime: ?string} */
    public function descargar(User $u, string $archivo): array
    {
        $r = $this->http($u)->get("https://www.googleapis.com/drive/v3/files/{$archivo}", ['alt' => 'media', 'supportsAllDrives' => 'true']);
        if (! $r->successful()) {
            throw new RuntimeException('No se pudo descargar un archivo de Drive ('.$r->status().'). Si es un Documento de Google, pida que lo entreguen en PDF.');
        }

        return ['contenido' => $r->body(), 'mime' => $r->header('Content-Type')];
    }

    /** Pone la nota: como borrador (el docente la devuelve en Classroom) o también como asignada. */
    public function calificar(User $u, string $curso, string $tarea, string $entrega, float $puntos, bool $asignar): void
    {
        $campos = $asignar ? ['draftGrade' => $puntos, 'assignedGrade' => $puntos] : ['draftGrade' => $puntos];
        $this->revisar($this->http($u)->patch(self::API."/courses/{$curso}/courseWork/{$tarea}/studentSubmissions/{$entrega}?updateMask=".implode(',', array_keys($campos)), $campos));
    }

    private function tarea(array $w): array
    {
        return ['id' => $w['id'], 'titulo' => $w['title'] ?? '', 'puntos' => isset($w['maxPoints']) ? (float) $w['maxPoints'] : null,
            'propia' => (bool) ($w['associatedWithDeveloper'] ?? false), 'enlace' => $w['alternateLink'] ?? null];
    }

    private function todo(User $u, string $ruta, string $clave, array $q = []): array
    {
        $items = [];
        $pagina = null;
        do {
            $d = $this->revisar($this->http($u)->get(self::API.$ruta, $q + array_filter(['pageToken' => $pagina, 'pageSize' => 100])));
            array_push($items, ...($d[$clave] ?? []));
            $pagina = $d['nextPageToken'] ?? null;
        } while ($pagina);

        return $items;
    }

    private function http(User $u)
    {
        return Http::withToken($this->conexion->token($u))->acceptJson()->timeout(30);
    }

    private function revisar(Response $r): array
    {
        if ($r->successful()) {
            return $r->json() ?? [];
        }
        $estado = $r->json('error.status');
        $mensaje = (string) $r->json('error.message');
        throw new RuntimeException(match (true) {
            $estado === 'PERMISSION_DENIED' && str_contains($mensaje, 'Developer Console project') => 'Classroom solo deja poner notas en tareas creadas por esta aplicación. Cree la tarea desde la plataforma (o lleve las notas a mano para esta).',
            $estado === 'PERMISSION_DENIED' => 'Google no le da permiso para esto ('.$mensaje.'). Revise que sea docente del curso.',
            $estado === 'NOT_FOUND' => 'Classroom no encontró el curso o la tarea: quizá se archivó o se borró.',
            $r->status() === 401 => 'La conexión con Google venció: vuelva a conectar su cuenta.',
            default => 'Classroom respondió con un error ('.$r->status().'): '.$mensaje,
        });
    }
}
