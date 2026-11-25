<?php

namespace App\Evaluacion;

use App\Models\ClaveCifrado;
use App\Models\Dictado;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use RuntimeException;

/**
 * Un par de claves RSA-2048 por dictado (revisión A2). La pública viaja en el examen del
 * estudiante; la privada se guarda cifrada con APP_KEY y solo se usa en el servidor.
 *
 * Sobre de cifrado compatible con Web Crypto: una clave AES-256-GCM aleatoria cifra los datos,
 * y esa clave se envuelve con RSA-OAEP (SHA-256, MGF1-SHA-256).
 */
final class ClavesDictado
{
    public const ALGORITMO = 'RSA-OAEP-256+A256GCM';

    public function de(Dictado $dictado): ClaveCifrado
    {
        $existente = $dictado->claveCifrado()->first();
        if ($existente) {
            return $existente;
        }

        $privada = RSA::createKey(2048);

        return $dictado->claveCifrado()->create([
            'publica_jwk' => $privada->getPublicKey()->toString('PKCS8'),   // SPKI en PEM
            'privada_jwk' => $privada->toString('PKCS8'),
        ]);
    }

    /** SPKI en base64 (sin encabezados PEM), listo para crypto.subtle.importKey('spki', ...). */
    public function publicaSpkiBase64(Dictado $dictado): string
    {
        return preg_replace('/-----[^-]+-----|\s+/', '', $this->de($dictado)->publica_jwk);
    }

    /**
     * @param  array{alg: string, clave: string, iv: string, datos: string}  $cifrado
     */
    public function descifrar(Dictado $dictado, array $cifrado): string
    {
        if (($cifrado['alg'] ?? null) !== self::ALGORITMO) {
            throw new RuntimeException('Algoritmo de cifrado no soportado.');
        }

        $privada = PublicKeyLoader::load($this->de($dictado)->privada_jwk)
            ->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256');

        $claveAes = @$privada->decrypt($this->b64($cifrado['clave']));
        if ($claveAes === false || strlen($claveAes) !== 32) {
            throw new RuntimeException('No se pudo descifrar la clave: la entrega no corresponde a este periodo o está dañada.');
        }

        $bytes = $this->b64($cifrado['datos']);
        $tag = substr($bytes, -16);
        $texto = openssl_decrypt(substr($bytes, 0, -16), 'aes-256-gcm', $claveAes, OPENSSL_RAW_DATA, $this->b64($cifrado['iv']), $tag);
        if ($texto === false) {
            throw new RuntimeException('Los datos cifrados fueron alterados (falló la verificación de integridad).');
        }

        return $texto;
    }

    /**
     * Cifra como lo hace el navegador. Se usa en las pruebas del sistema y en el modo de ensayo
     * para comprobar el ciclo de ida y vuelta (9.2.6).
     *
     * @return array{alg: string, clave: string, iv: string, datos: string}
     */
    public function cifrar(Dictado $dictado, string $texto): array
    {
        $publica = PublicKeyLoader::load($this->de($dictado)->publica_jwk)
            ->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256');
        $claveAes = random_bytes(32);
        $iv = random_bytes(12);
        $tag = '';
        $datos = openssl_encrypt($texto, 'aes-256-gcm', $claveAes, OPENSSL_RAW_DATA, $iv, $tag);

        return [
            'alg' => self::ALGORITMO,
            'clave' => base64_encode($publica->encrypt($claveAes)),
            'iv' => base64_encode($iv),
            'datos' => base64_encode($datos.$tag),
        ];
    }

    private function b64(string $v): string
    {
        $r = base64_decode($v, true);
        if ($r === false) {
            throw new RuntimeException('El sobre de la entrega no es base64 válido.');
        }

        return $r;
    }
}
