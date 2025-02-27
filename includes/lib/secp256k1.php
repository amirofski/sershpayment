<?php
/**
 * Pure PHP implementation of secp256k1 using BCMath
 */

class Secp256k1 {
    const N = '115792089237316195423570985008687907852837564279074904382605163141518161494337';
    const P = '115792089237316195423570985008687907853269984665640564039457584007908834671663';
    const A = '0';
    const B = '7';
    const GX = '55066263022277343669578718895168534326250603453777594175500187360389116729240';
    const GY = '32670510020758816978083085130507043184471273380659243275938904335757337482424';

    public function __construct() {
        if (!extension_loaded('bcmath')) {
            throw new Exception('BCMath extension required for secp256k1');
        }
        bcscale(0); // Set default scale to 0 for integer arithmetic
    }

    /**
     * Sign a message with a private key
     *
     * @param string $message Message to sign (32 bytes)
     * @param string $privateKey Private key in hex
     * @return array Signature components [r, s, v]
     */
    public function sign($message, $privateKey) {
        $privateKey = $this->removeHexPrefix($privateKey);
        $message = $this->removeHexPrefix($message);

        if (strlen($message) !== 64) {
            throw new Exception('Message must be 32 bytes long');
        }
        if (strlen($privateKey) !== 64) {
            throw new Exception('Private key must be 32 bytes long');
        }

        // Convert message and private key to decimal
        $msg = $this->hexdec($message);
        $priv = $this->hexdec($privateKey);

        // Generate k value
        $k = $this->deterministicGenerateK($message, $privateKey, 'sha256');

        // Calculate curve point (x, y) = k * G
        $point = $this->mulPoint([self::GX, self::GY], $k);
        
        // r = x mod n
        $r = bcmod($point[0], self::N);
        if (bccomp($r, '0') === 0) {
            throw new Exception('Invalid r value');
        }

        // s = k^-1 * (msg + r * priv) mod n
        $s = bcmod(
            bcmul(
                $this->modinv($k, self::N),
                bcadd(
                    $msg,
                    bcmul($priv, $r)
                )
            ),
            self::N
        );

        if (bccomp($s, '0') === 0) {
            throw new Exception('Invalid s value');
        }

        // Ensure low S value
        $halfN = bcdiv(self::N, '2');
        if (bccomp($s, $halfN) > 0) {
            $s = bcsub(self::N, $s);
        }

        // Calculate recovery id
        $recid = 0;
        $y_parity = bcmod($point[1], '2');
        if ($y_parity === '1') {
            $recid |= 1;
        }

        // Convert values to hex
        $r_hex = str_pad($this->bcdechex($r), 64, '0', STR_PAD_LEFT);
        $s_hex = str_pad($this->bcdechex($s), 64, '0', STR_PAD_LEFT);
        $v_hex = dechex(27 + $recid);

        return [
            'r' => $r_hex,
            's' => $s_hex,
            'v' => $v_hex
        ];
    }

    /**
     * Get public key from private key
     *
     * @param string $privateKey Private key in hex
     * @return string Uncompressed public key
     */
    public function getPublicKey($privateKey) {
        $privateKey = $this->removeHexPrefix($privateKey);
        
        if (strlen($privateKey) !== 64) {
            throw new Exception('Private key must be 32 bytes long');
        }

        $k = $this->hexdec($privateKey);

        if (bccomp($k, '0') <= 0 || bccomp($k, bcsub(self::N, '1')) > 0) {
            throw new Exception('Invalid private key');
        }

        $point = $this->mulPoint([self::GX, self::GY], $k);
        
        // Convert to uncompressed public key format
        $x = str_pad($this->bcdechex($point[0]), 64, '0', STR_PAD_LEFT);
        $y = str_pad($this->bcdechex($point[1]), 64, '0', STR_PAD_LEFT);

        return '04' . $x . $y;
    }

    /**
     * Calculate deterministic K according to RFC 6979
     */
    private function deterministicGenerateK($message, $privateKey, $algo) {
        $hash = hash($algo, hex2bin($message));
        $v = str_repeat('01', 32);
        $k = str_repeat('00', 32);

        $k = hash_hmac($algo, $v . '00' . $privateKey . $hash, $k, true);
        $v = hash_hmac($algo, $v, $k, true);
        $k = hash_hmac($algo, $v . '01' . $privateKey . $hash, $k, true);
        $v = hash_hmac($algo, $v, $k, true);

        $k = $this->hexdec(bin2hex($k));

        while (true) {
            if (bccomp($k, '0') > 0 && bccomp($k, self::N) < 0) {
                break;
            }
            $k = hash_hmac($algo, $v . '00', $k, true);
            $v = hash_hmac($algo, $v, $k, true);
            $k = $this->hexdec(bin2hex($k));
        }

        return $k;
    }

    /**
     * Calculate R value
     */
    private function calculateR($k) {
        $point = $this->mulPoint([self::GX, self::GY], $k);
        return $this->bcdechex(bcmod($point[0], self::N));
    }

    /**
     * Calculate S value
     */
    private function calculateS($message, $r, $k, $privateKey) {
        $r = $this->hexdec($r);
        $msg = $this->hexdec($message);
        $priv = $this->hexdec($privateKey);
        
        $s = bcmod(
            bcmul(
                $this->modinv($k, self::N),
                bcadd(
                    $msg,
                    bcmul($priv, $r)
                )
            ),
            self::N
        );

        // Ensure low S value
        if (bccomp($s, bcdiv(self::N, '2')) > 0) {
            $s = bcsub(self::N, $s);
        }

        return $this->bcdechex($s);
    }

    /**
     * Calculate recovery ID
     */
    private function calculateRecoveryId($message, $r, $s, $privateKey) {
        $recid = -1;

        for ($i = 0; $i < 4; $i++) {
            try {
                $pubkey = $this->recoverPubKey($message, $r, $s, $i);
                if ($pubkey === $this->getPublicKey($privateKey)) {
                    $recid = $i;
                    break;
                }
            } catch (Exception $e) {
                continue;
            }
        }

        if ($recid === -1) {
            throw new Exception('Unable to find valid recovery factor');
        }

        return $recid;
    }

    /**
     * Recover public key
     */
    private function recoverPubKey($message, $r, $s, $recid) {
        $msg = $this->hexdec($message);
        $r = $this->hexdec($r);
        $s = $this->hexdec($s);

        if (bccomp($r, '1') < 0 || bccomp($r, bcsub(self::N, '1')) > 0) {
            throw new Exception('Invalid r value');
        }
        if (bccomp($s, '1') < 0 || bccomp($s, bcsub(self::N, '1')) > 0) {
            throw new Exception('Invalid s value');
        }

        // The point's x coordinate equals r
        $x = bcmod($r, self::P);
        
        // If first key recovery method fails, try the second
        if ($recid >= 2) {
            $x = bcmod(bcadd($x, self::N), self::P);
        }

        // Derive y coordinate: y² = x³ + 7 (mod p)
        $y_squared = bcmod(
            bcadd(
                bcpow($x, '3'),
                self::B
            ),
            self::P
        );

        // Calculate y coordinate
        $y = $this->modularSqrt($y_squared, self::P);

        // Ensure y has correct parity based on recovery id
        if (bcmod($y, '2') !== (string)($recid & 1)) {
            $y = bcmod(bcsub(self::P, $y), self::P);
        }

        // Construct R point
        $R = [$x, $y];

        // Verify R is on the curve
        $y_check = bcmod(
            bcadd(
                bcpow($x, '3'),
                self::B
            ),
            self::P
        );
        if (bccomp(bcpow($y, '2'), $y_check) !== 0) {
            throw new Exception('Point is not on the curve');
        }

        // Calculate r_inv = r^-1 mod n
        $r_inv = $this->modinv($r, self::N);

        // Calculate u1 = -message * r_inv mod n
        $u1 = bcmod(
            bcmul(
                bcmod(bcsub('0', $msg), self::N),
                $r_inv
            ),
            self::N
        );

        // Calculate u2 = s * r_inv mod n
        $u2 = bcmod(bcmul($s, $r_inv), self::N);

        // Calculate Q = u1*G + u2*R
        $point1 = $this->mulPoint([self::GX, self::GY], $u1);
        $point2 = $this->mulPoint($R, $u2);
        $Q = $this->addPoints($point1, $point2);

        // Convert to uncompressed public key format
        $pubkey = '04' . 
            str_pad($this->bcdechex($Q[0]), 64, '0', STR_PAD_LEFT) . 
            str_pad($this->bcdechex($Q[1]), 64, '0', STR_PAD_LEFT);

        return $pubkey;
    }

    /**
     * Calculate negative of a number in modular arithmetic
     */
    private function bcneg($num) {
        return bcmod(bcsub('0', $num), self::N);
    }

    /**
     * Point multiplication
     */
    private function mulPoint($p, $n) {
        $r = ['0', '0'];
        $n = $this->dec2bin($n);
        
        for ($i = 0; $i < strlen($n); $i++) {
            $r = $this->doublePoint($r);
            if ($n[$i] === '1') {
                $r = $this->addPoints($r, $p);
            }
        }

        return $r;
    }

    /**
     * Point addition
     */
    private function addPoints($p1, $p2) {
        if (bccomp($p1[0], '0') === 0 && bccomp($p1[1], '0') === 0) {
            return $p2;
        }
        if (bccomp($p2[0], '0') === 0 && bccomp($p2[1], '0') === 0) {
            return $p1;
        }
        
        if (bccomp($p1[0], $p2[0]) === 0) {
            if (bccomp($p1[1], $p2[1]) === 0) {
                return $this->doublePoint($p1);
            }
            return ['0', '0'];
        }

        $slope = bcmod(
            bcmul(
                bcsub($p2[1], $p1[1]),
                $this->modinv(bcsub($p2[0], $p1[0]), self::P)
            ),
            self::P
        );

        $x3 = bcmod(
            bcsub(
                bcsub(
                    bcpow($slope, '2'),
                    $p1[0]
                ),
                $p2[0]
            ),
            self::P
        );

        $y3 = bcmod(
            bcsub(
                bcmul($slope, bcsub($p1[0], $x3)),
                $p1[1]
            ),
            self::P
        );

        return [$x3, $y3];
    }

    /**
     * Point doubling
     */
    private function doublePoint($p) {
        if (bccomp($p[0], '0') === 0 && bccomp($p[1], '0') === 0) {
            return $p;
        }

        $slope = bcmod(
            bcmul(
                '3',
                bcmul(
                    $p[0],
                    $this->modinv(
                        bcmul('2', $p[1]),
                        self::P
                    )
                )
            ),
            self::P
        );

        $x3 = bcmod(
            bcsub(
                bcpow($slope, '2'),
                bcmul('2', $p[0])
            ),
            self::P
        );

        $y3 = bcmod(
            bcsub(
                bcmul($slope, bcsub($p[0], $x3)),
                $p[1]
            ),
            self::P
        );

        return [$x3, $y3];
    }

    /**
     * Calculate modular square root
     * For P ≡ 3 (mod 4), we can compute sqrt(a) = a^((P+1)/4) mod P
     */
    private function modularSqrt($a, $p) {
        // For P ≡ 3 (mod 4), we can compute sqrt(a) = a^((P+1)/4) mod P
        if (bcmod($p, '4') === '3') {
            // Calculate (P+1)/4
            $exp = bcdiv(bcadd($p, '1'), '4');
            
            // Calculate a^((P+1)/4) mod P using square-and-multiply
            $result = '1';
            $base = bcmod($a, $p);
            $exp = $this->dec2bin($exp);
            
            for ($i = 0; $i < strlen($exp); $i++) {
                $result = bcmod(bcmul($result, $result), $p);
                if ($exp[$i] === '1') {
                    $result = bcmod(bcmul($result, $base), $p);
                }
            }
            
            // Verify the result
            $check = bcmod(bcpow($result, '2'), $p);
            if (bccomp(bcmod($check, $p), bcmod($a, $p)) === 0) {
                return $result;
            }
            
            throw new Exception('No modular square root exists');
        }
        
        throw new Exception('Currently only p mod 4 = 3 is supported');
    }

    /**
     * Modular multiplicative inverse using Extended Euclidean Algorithm
     */
    private function modinv($a, $m) {
        $a = bcmod($a, $m);
        if (bccomp($a, '0') < 0) {
            $a = bcadd($a, $m);
        }
        
        $t = '0';
        $newt = '1';
        $r = $m;
        $newr = $a;
        
        while (bccomp($newr, '0') !== 0) {
            $quotient = bcdiv($r, $newr);
            
            $tempt = $newt;
            $newt = bcsub($t, bcmul($quotient, $newt));
            $t = $tempt;
            
            $tempr = $newr;
            $newr = bcsub($r, bcmul($quotient, $newr));
            $r = $tempr;
        }
        
        if (bccomp($r, '1') > 0) {
            throw new Exception('Number is not invertible');
        }
        if (bccomp($t, '0') < 0) {
            $t = bcadd($t, $m);
        }
        
        return $t;
    }

    /**
     * Convert decimal string to binary string
     */
    private function dec2bin($dec) {
        $bin = '';
        while (bccomp($dec, '0') > 0) {
            $bin = bcmod($dec, '2') . $bin;
            $dec = bcdiv($dec, '2');
        }
        return $bin;
    }

    /**
     * Convert hex to decimal string
     */
    private function hexdec($hex) {
        $dec = '0';
        $len = strlen($hex);
        for ($i = 0; $i < $len; $i++) {
            $dec = bcadd(bcmul($dec, '16'), hexdec($hex[$i]));
        }
        return $dec;
    }

    /**
     * Convert decimal string to hex
     */
    private function bcdechex($dec) {
        $hex = '';
        while (bccomp($dec, '0') > 0) {
            $hex = dechex(bcmod($dec, '16')) . $hex;
            $dec = bcdiv($dec, '16');
        }
        return $hex;
    }

    /**
     * Remove 0x prefix from hex string
     */
    private function removeHexPrefix($hex) {
        if (substr($hex, 0, 2) === '0x') {
            return substr($hex, 2);
        }
        return $hex;
    }
} 