<?php
declare(strict_types=1);

namespace App\Service\Import;

use RuntimeException;

final class TotvsHeaderValidator
{
    /** @var list<string> */
    public const EXPECTED = [
        'Filial', 'Ordem Serv.', 'Plano Manut.', 'Data Origin.', 'Tipo O. S.', 'Bem', 'Nome do Bem', 'Serviço',
        'Nome Serviço', 'Sequência', 'Tipo Manut.', 'Área Manut.', 'Centro Custo', 'Contador', 'Hora cont. 1',
        'Custo M-D-O', 'Custo Troca', 'Custo Mater.', 'Custo Subst.', 'Custo Terc.', 'Ult. Man.', 'Cont. Man.',
        'Prev. Início', 'Prev. Início', 'Prev. Fim', 'Prev. Fim', 'Real. Início', 'Real. Início', 'Real. Fim',
        'Real. Fim', 'P. In. Man.', 'P. In. Man.', 'P. Fim Man.', 'P. Fim Man.', 'R. In. Man.', 'R. In. Man.',
        'R. Fim Man.', 'R. Fim Man.', 'Pos. Cont.', 'Contador 2', 'Término', 'Usuário Alt.', 'Prioridade',
        'Hora cont. 2', 'Situação', 'Centro Trab.', 'Tipo Retorno', 'Ordem Pai', 'Bem Pai', 'Substit. O.S',
        'Sol. Serviço', 'Cod. Irreg.', 'Terceiro', 'Quant. Repr.', 'Motivo Repr.', 'Custo Ferr.', 'O.S. Orig.',
    ];

    /** @param list<mixed> $headers */
    public function validate(array $headers): array
    {
        if (count($headers) !== count(self::EXPECTED)) {
            throw new RuntimeException(sprintf('Estrutura inválida: esperadas 57 colunas, encontradas %d.', count($headers)));
        }
        $warnings = [];
        foreach (self::EXPECTED as $index => $expected) {
            $actual = trim((string)$headers[$index]);
            if (!$this->matches($actual, $expected)) {
                throw new RuntimeException(sprintf('Coluna %d inválida: esperado "%s", encontrado "%s".', $index + 1, $expected, $actual));
            }
            if (str_contains($actual, "\u{FFFD}")) {
                $warnings[] = sprintf('Cabeçalho %d contém caractere de substituição Unicode.', $index + 1);
            }
        }

        return array_values(array_unique($warnings));
    }

    private function matches(string $actual, string $expected): bool
    {
        if ($actual === $expected) {
            return true;
        }
        // O arquivo real substitui cada caractere acentuado por U+FFFD.
        $expectedCorrupt = strtr($expected, ['ç' => '�', 'ã' => '�', 'á' => '�', 'é' => '�', 'í' => '�', 'ó' => '�', 'ú' => '�', 'ê' => '�', 'Ç' => '�', 'Ã' => '�', 'Á' => '�', 'É' => '�', 'Í' => '�', 'Ó' => '�', 'Ú' => '�', 'Ê' => '�']);

        return $actual === $expectedCorrupt;
    }
}
