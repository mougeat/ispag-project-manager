<?php
defined('ABSPATH') || exit;

/**
 * Générateur de QR code (mode octets, correction d'erreur M, versions 1 à 10 : jusqu'à ~210 caractères).
 * Aucune dépendance ni appel externe : la matrice est dessinée en vectoriel dans le PDF (voir ISPAG_Delivery_Note_PDF).
 * Algorithme d'après la spécification ISO/IEC 18004 (organisation inspirée de l'implémentation de référence de Project Nayuki, MIT).
 */
class ISPAG_QR_Code {

    // Niveau M : mots de code de correction par bloc / nombre de blocs, index = version (1..10)
    const ECC_PER_BLOCK = [0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26];
    const NUM_BLOCKS    = [0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5];
    const FORMAT_M      = 0;

    private $version;
    private $size;
    private $modules = [];
    private $isFunction = [];

    /** @return bool[][] matrice [y][x] (true = module sombre) */
    public static function matrix(string $text): array {
        $data = array_values(unpack('C*', $text) ?: []);
        for ($ver = 1; $ver <= 10; $ver++) {
            $capacityBits = self::dataCodewords($ver) * 8;
            $lenBits = $ver < 10 ? 8 : 16;
            if (4 + $lenBits + count($data) * 8 <= $capacityBits) {
                $qr = new self($ver, $data);
                return $qr->modules;
            }
        }
        throw new InvalidArgumentException('Text too long for the QR code');
    }

    private static function rawModules(int $ver): int {
        $r = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) {
            $a = intdiv($ver, 7) + 2;
            $r -= (25 * $a - 10) * $a - 55;
            if ($ver >= 7) $r -= 36;
        }
        return $r;
    }

    private static function dataCodewords(int $ver): int {
        return intdiv(self::rawModules($ver), 8) - self::ECC_PER_BLOCK[$ver] * self::NUM_BLOCKS[$ver];
    }

    private function __construct(int $ver, array $bytes) {
        $this->version = $ver;
        $this->size = $ver * 4 + 17;
        for ($y = 0; $y < $this->size; $y++) {
            $this->modules[$y] = array_fill(0, $this->size, false);
            $this->isFunction[$y] = array_fill(0, $this->size, false);
        }

        // --- Bits de données : mode octets, longueur, données, terminateur, bourrage ---
        $bits = [];
        $put = function (int $val, int $len) use (&$bits) { for ($i = $len - 1; $i >= 0; $i--) $bits[] = ($val >> $i) & 1; };
        $put(0b0100, 4);
        $put(count($bytes), $ver < 10 ? 8 : 16);
        foreach ($bytes as $b) $put($b, 8);
        $capacity = self::dataCodewords($ver) * 8;
        $put(0, min(4, $capacity - count($bits)));
        while (count($bits) % 8) $bits[] = 0;
        $data = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $v = 0;
            for ($j = 0; $j < 8; $j++) $v = ($v << 1) | $bits[$i + $j];
            $data[] = $v;
        }
        for ($pad = 0xEC; count($data) < self::dataCodewords($ver); $pad ^= 0xEC ^ 0x11) $data[] = $pad;

        $all = $this->addEccAndInterleave($data);

        $this->drawFunctionPatterns();
        $this->drawCodewords($all);

        // Choix du masque : pénalité minimale
        $best = 0; $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask);
            $this->drawFormatBits($mask);
            $p = $this->penalty();
            if ($p < $bestPenalty) { $bestPenalty = $p; $best = $mask; }
            $this->applyMask($mask); // annule (XOR)
        }
        $this->applyMask($best);
        $this->drawFormatBits($best);
        $this->isFunction = [];
    }

    // ------------------------------------------------------------------ Reed-Solomon

    private static function gfMul(int $x, int $y): int {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z;
    }

    private static function rsGenerator(int $degree): array {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gfMul($result[$j], $root);
                if ($j + 1 < $degree) $result[$j] ^= $result[$j + 1];
            }
            $root = self::gfMul($root, 0x02);
        }
        return $result;
    }

    private static function rsRemainder(array $data, array $divisor): array {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coef) $result[$i] ^= self::gfMul($coef, $factor);
        }
        return $result;
    }

    private function addEccAndInterleave(array $data): array {
        $ver = $this->version;
        $numBlocks = self::NUM_BLOCKS[$ver];
        $eccLen = self::ECC_PER_BLOCK[$ver];
        $raw = intdiv(self::rawModules($ver), 8);
        $numShort = $numBlocks - $raw % $numBlocks;
        $shortLen = intdiv($raw, $numBlocks);

        $blocks = [];
        $div = self::rsGenerator($eccLen);
        for ($i = 0, $k = 0; $i < $numBlocks; $i++) {
            $len = $shortLen - $eccLen + ($i < $numShort ? 0 : 1);
            $dat = array_slice($data, $k, $len);
            $k += $len;
            $ecc = self::rsRemainder($dat, $div);
            if ($i < $numShort) $dat[] = 0;
            $blocks[] = array_merge($dat, $ecc);
        }
        $result = [];
        for ($i = 0; $i < count($blocks[0]); $i++) {
            foreach ($blocks as $j => $block) {
                if ($i !== $shortLen - $eccLen || $j >= $numShort) $result[] = $block[$i];
            }
        }
        return $result;
    }

    // ------------------------------------------------------------------ dessin

    private function setFunction(int $x, int $y, bool $dark) {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function alignmentPositions(): array {
        $ver = $this->version;
        if ($ver === 1) return [];
        $num = intdiv($ver, 7) + 2;
        $step = intdiv($ver * 8 + $num * 3 + 5, $num * 4 - 4) * 2;
        $result = [6];
        for ($pos = $this->size - 7; count($result) < $num; $pos -= $step) array_splice($result, 1, 0, [$pos]);
        return $result;
    }

    private function drawFunctionPatterns() {
        $size = $this->size;
        for ($i = 0; $i < $size; $i++) {
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $dist = max(abs($dx), abs($dy));
                    $xx = $cx + $dx; $yy = $cy + $dy;
                    if ($xx >= 0 && $xx < $size && $yy >= 0 && $yy < $size) $this->setFunction($xx, $yy, $dist !== 2 && $dist !== 4);
                }
            }
        }
        $pos = $this->alignmentPositions();
        $n = count($pos);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) continue;
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) $this->setFunction($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                }
            }
        }
        $this->drawFormatBits(0); // réserve l'emplacement
        $this->drawVersion();
    }

    private function drawFormatBits(int $mask) {
        $size = $this->size;
        $data = (self::FORMAT_M << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = function (int $i) use ($bits) { return (($bits >> $i) & 1) !== 0; };
        for ($i = 0; $i <= 5; $i++) $this->setFunction(8, $i, $bit($i));
        $this->setFunction(8, 7, $bit(6));
        $this->setFunction(8, 8, $bit(7));
        $this->setFunction(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) $this->setFunction(14 - $i, 8, $bit($i));
        for ($i = 0; $i < 8; $i++) $this->setFunction($size - 1 - $i, 8, $bit($i));
        for ($i = 8; $i < 15; $i++) $this->setFunction(8, $size - 15 + $i, $bit($i));
        $this->setFunction(8, $size - 8, true);
    }

    private function drawVersion() {
        if ($this->version < 7) return;
        $rem = $this->version;
        for ($i = 0; $i < 12; $i++) $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        $bits = ($this->version << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $b = (($bits >> $i) & 1) !== 0;
            $a = $this->size - 11 + $i % 3;
            $c = intdiv($i, 3);
            $this->setFunction($a, $c, $b);
            $this->setFunction($c, $a, $b);
        }
    }

    private function drawCodewords(array $data) {
        $size = $this->size;
        $i = 0;
        $total = count($data) * 8;
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) $right = 5;
            for ($vert = 0; $vert < $size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $size - 1 - $vert : $vert;
                    if (!$this->isFunction[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = ((($data[$i >> 3] >> (7 - ($i & 7))) & 1) !== 0);
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask) {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                switch ($mask) {
                    case 0: $inv = ($x + $y) % 2 === 0; break;
                    case 1: $inv = $y % 2 === 0; break;
                    case 2: $inv = $x % 3 === 0; break;
                    case 3: $inv = ($x + $y) % 3 === 0; break;
                    case 4: $inv = (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0; break;
                    case 5: $inv = $x * $y % 2 + $x * $y % 3 === 0; break;
                    case 6: $inv = ($x * $y % 2 + $x * $y % 3) % 2 === 0; break;
                    default: $inv = (($x + $y) % 2 + $x * $y % 3) % 2 === 0;
                }
                if (!$this->isFunction[$y][$x] && $inv) $this->modules[$y][$x] = !$this->modules[$y][$x];
            }
        }
    }

    /** Pénalité de la norme (séries, blocs 2×2, motifs de repérage, équilibre noir/blanc). */
    private function penalty(): int {
        $size = $this->size;
        $m = $this->modules;
        $p = 0;
        $lines = [];
        for ($y = 0; $y < $size; $y++) {
            $row = ''; $col = '';
            for ($x = 0; $x < $size; $x++) { $row .= $m[$y][$x] ? '1' : '0'; $col .= $m[$x][$y] ? '1' : '0'; }
            $lines[] = $row; $lines[] = $col;
        }
        foreach ($lines as $l) {
            if (preg_match_all('/(0{5,}|1{5,})/', $l, $mm)) foreach ($mm[0] as $run) $p += 3 + strlen($run) - 5;
            $p += 40 * (preg_match_all('/(?=10111010000|00001011101)/', $l));
        }
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                if ($m[$y][$x] === $m[$y][$x + 1] && $m[$y][$x] === $m[$y + 1][$x] && $m[$y][$x] === $m[$y + 1][$x + 1]) $p += 3;
            }
        }
        $dark = 0;
        foreach ($m as $row) foreach ($row as $v) if ($v) $dark++;
        $total = $size * $size;
        $k = (int) ceil(abs($dark * 20 - $total * 10) / $total) - 1;
        return $p + max(0, $k) * 10;
    }
}
