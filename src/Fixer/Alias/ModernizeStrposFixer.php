<?php

declare(strict_types=1);

/*
 * This file is part of PHP CS Fixer.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *     Dariusz Rumiński <dariusz.ruminski@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace PhpCsFixer\Fixer\Alias;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\Analyzer\ArgumentsAnalyzer;
use PhpCsFixer\Tokenizer\Analyzer\FunctionsAnalyzer;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

/**
 * @author Alexander M. Turek <me@derrabus.de>
 */
final class ModernizeStrposFixer extends AbstractFixer
{
    private const REPLACEMENTS = [
        [
            'operator' => [T_IS_IDENTICAL, '==='],
            'operand' => [T_LNUMBER, '0'],
            'replacement' => [T_STRING, 'str_starts_with'],
            'negate' => false,
        ],
        [
            'operator' => [T_IS_NOT_IDENTICAL, '!=='],
            'operand' => [T_LNUMBER, '0'],
            'replacement' => [T_STRING, 'str_starts_with'],
            'negate' => true,
        ],
        [
            'operator' => [T_IS_NOT_IDENTICAL, '!=='],
            'operand' => [T_STRING, 'false'],
            'replacement' => [T_STRING, 'str_contains'],
            'negate' => false,
        ],
        [
            'operator' => [T_IS_IDENTICAL, '==='],
            'operand' => [T_STRING, 'false'],
            'replacement' => [T_STRING, 'str_contains'],
            'negate' => true,
        ],
    ];

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Replace `strpos()` calls with `str_starts_with()` or `str_contains()` if possible.',
            [
                new CodeSample(
                    '<?php
if (strpos($haystack, $needle) === 0) {}
if (strpos($haystack, $needle) !== 0) {}
if (strpos($haystack, $needle) !== false) {}
if (strpos($haystack, $needle) === false) {}
'
                ),
            ],
            null,
            'Risky if `strpos`, `str_starts_with` or `str_contains` functions are overridden.'
        );
    }

    /**
     * {@inheritdoc}
     *
     * Must run before BinaryOperatorSpacesFixer, NoExtraBlankLinesFixer, NoSpacesInsideParenthesisFixer, NoTrailingWhitespaceFixer, NotOperatorWithSpaceFixer, NotOperatorWithSuccessorSpaceFixer, SingleSpaceAfterConstructFixer.
     */
    public function getPriority(): int
    {
        return 37;
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(T_STRING) && $tokens->isAnyTokenKindsFound([T_IS_IDENTICAL, T_IS_NOT_IDENTICAL]);
    }

    public function isRisky(): bool
    {
        return true;
    }

    protected function applyFix(\SplFileInfo $file, Tokens $tokens): void
    {
        $functionsAnalyzer = new FunctionsAnalyzer();
        $argumentsAnalyzer = new ArgumentsAnalyzer();

        for ($index = \count($tokens) - 1; $index > 0; --$index) {
            // find candidate function call
            if (!$tokens[$index]->equals([T_STRING, 'strpos'], false) || !$functionsAnalyzer->isGlobalFunctionCall($tokens, $index)) {
                continue;
            }

            // assert called with 2 arguments
            $openIndex = $tokens->getNextMeaningfulToken($index);
            $closeIndex = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $openIndex);
            $arguments = $argumentsAnalyzer->getArguments($tokens, $openIndex, $closeIndex);

            if (2 !== \count($arguments)) {
                continue;
            }

            // check if part condition and fix if needed
            $compareTokens = $this->getCompareTokens($tokens, $index, -1); // look behind

            if (null === $compareTokens) {
                $compareTokens = $this->getCompareTokens($tokens, $closeIndex, 1); // look ahead
            }

            if (null !== $compareTokens) {
                $this->fixCall($tokens, $index, $compareTokens);
            }
        }
    }

    private function fixCall(Tokens $tokens, int $functionIndex, array $operatorIndexes): void
    {
        foreach (self::REPLACEMENTS as $replacement) {
            if (!$tokens[$operatorIndexes['operator_index']]->equals($replacement['operator'])) {
                continue;
            }

            if (!$tokens[$operatorIndexes['operand_index']]->equals($replacement['operand'], false)) {
                continue;
            }

            $tokens->clearTokenAndMergeSurroundingWhitespace($operatorIndexes['operator_index']);
            $tokens->clearTokenAndMergeSurroundingWhitespace($operatorIndexes['operand_index']);
            $tokens->clearTokenAndMergeSurroundingWhitespace($functionIndex);

            if ($replacement['negate']) {
                $tokens->insertAt($functionIndex, new Token('!'));
                ++$functionIndex;
            }

            $tokens->insertAt($functionIndex, new Token($replacement['replacement']));

            break;
        }
    }

    private function getCompareTokens(Tokens $tokens, int $offsetIndex, int $direction): ?array
    {
        $operatorIndex = $tokens->getMeaningfulTokenSibling($offsetIndex, $direction);

        if (null === $operatorIndex) {
            return null;
        }

        $operandIndex = $tokens->getMeaningfulTokenSibling($operatorIndex, $direction);

        if (null === $operandIndex) {
            return null;
        }

        $operand = $tokens[$operandIndex];

        if (!$operand->equals([T_LNUMBER, '0']) && !$operand->equals([T_STRING, 'false'], false)) {
            return null;
        }

        if (!$tokens[$operatorIndex]->isGivenKind([T_IS_IDENTICAL, T_IS_NOT_IDENTICAL])) {
            return null;
        }

        $precedenceTokenIndex = $tokens->getMeaningfulTokenSibling($operandIndex, $direction);

        if (null !== $precedenceTokenIndex && $this->isOfHigherPrecedence($tokens[$precedenceTokenIndex])) {
            return null;
        }

        return ['operator_index' => $operatorIndex, 'operand_index' => $operandIndex];
    }

    private function isOfHigherPrecedence(Token $token): bool
    {
        static $operatorsPerId = [
            T_DEC => true,                 // --
            T_INC => true,                 // ++
            T_INSTANCEOF => true,          // instanceof
            T_IS_GREATER_OR_EQUAL => true, // >=
            T_IS_SMALLER_OR_EQUAL => true, // <=
            T_POW => true,                 // **
            T_SL => true,                  // <<
            T_SR => true,                  // >>
        ];

        static $operatorsPerContent = [
            '!',
            '%',
            '*',
            '+',
            '-',
            '.',
            '/',
            '<',
            '>',
            '~',
        ];

        return isset($operatorsPerId[$token->getId()]) || $token->equalsAny($operatorsPerContent);
    }
}
