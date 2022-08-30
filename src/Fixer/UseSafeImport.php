<?php

declare(strict_types=1);

namespace Josecl\PhpCsFixerCustomFixers\Fixer;

use PhpCsFixer\Fixer\WhitespacesAwareFixerInterface;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\Analyzer\Analysis\NamespaceUseAnalysis;
use PhpCsFixer\Tokenizer\Analyzer\FunctionsAnalyzer;
use PhpCsFixer\Tokenizer\Analyzer\NamespacesAnalyzer;
use PhpCsFixer\Tokenizer\Analyzer\NamespaceUsesAnalyzer;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\WhitespacesFixerConfig;
use SplFileInfo;

final class UseSafeImport implements WhitespacesAwareFixerInterface
{
    /**
     * Lista de reemplazos, obtenida desde thecodingmachine/safe
     *
     * @var array<int, string>
     */
    private array $functions;

    private WhitespacesFixerConfig $whitespacesConfig;

    public function __construct()
    {
        $this->functions = self::getSafeFunctionList();
    }

    /**
     * Returns the Safe fucntion list if thecodingmachine/safe is available
     *
     * @return array<int, string>
     * @see https://github.com/thecodingmachine/safe/blob/master/rector-migrate.php
     */
    public static function getSafeFunctionList(): array
    {
        $paths = [
            __DIR__ . '/../../../../thecodingmachine/safe/generated/functionsList.php',
            __DIR__ . '/../../../vendor/thecodingmachine/safe/generated/functionsList.php',
            __DIR__ . '/../../vendor/thecodingmachine/safe/generated/functionsList.php',
        ];

        foreach ($paths as $path) {
            if (is_readable($path)) {
                return include $path;
            }
        }

        return [];
    }

    public function getName(): string
    {
        return 'Safe/use_safe_import';
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Imports or fully qualifies global classes/functions/constants.',
            [
                new CodeSample(
                    '<?php

use function Safe\\fopen;

$fp = fopen(\'/tmp/foo.txt\', \'w\');

'
                ), ]
        );
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isAnyTokenKindsFound([\T_DOC_COMMENT, \T_NS_SEPARATOR, \T_USE])
            && $tokens->isMonolithicPhp();
    }

    public function isRisky(): bool
    {
        return false;
    }

    public function fix(SplFileInfo $file, Tokens $tokens): void
    {
        if ($tokens->count() > 0 && $this->isCandidate($tokens) && $this->supports($file)) {
            $this->applyFix($file, $tokens);
        }
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function supports(SplFileInfo $file): bool
    {
        return true;
    }

    public function setWhitespacesConfig(WhitespacesFixerConfig $config): void
    {
        $this->whitespacesConfig = $config;
    }

    protected function applyFix(SplFileInfo $file, Tokens $tokens): void
    {
        $namespaceAnalyses = (new NamespacesAnalyzer())->getDeclarations($tokens);

        if (count($namespaceAnalyses) !== 1) {
            return;
        }

        $useDeclarations = (new NamespaceUsesAnalyzer())->getDeclarationsFromTokens($tokens);
        $newImports = [
            'function' => $this->importFunctions($tokens, $useDeclarations),
        ];

        $this->insertImports($tokens, $newImports, $useDeclarations);
    }

    /**
     * @param NamespaceUseAnalysis[] $useDeclarations
     */
    private function importFunctions(Tokens $tokens, array $useDeclarations): array
    {
        [$global, $other] = $this->filterUseDeclarations(
            $useDeclarations,
            static function (NamespaceUseAnalysis $declaration) {
                return $declaration->isFunction();
            },
            false
        );
        // find function declarations
        // and add them to the not importable names (already used)
        foreach ($this->findFunctionDeclarations($tokens, 0, $tokens->count() - 1) as $name) {
            $other[strtolower($name)] = \true;
        }
        $analyzer = new FunctionsAnalyzer();
        $indexes = [];
        for ($index = $tokens->count() - 1; $index >= 0; --$index) {
            $token = $tokens[$index];
            if (! $token?->isGivenKind(\T_STRING)) {
                continue;
            }
            $name = strtolower($token->getContent());
            if (isset($other[$name])) {
                continue;
            }
            if (! $analyzer->isGlobalFunctionCall($tokens, $index)) {
                continue;
            }
            if (! in_array($name, $this->functions, true)) {
                continue;
            }

            $indexes[] = $index;
        }

        return $this->prepareImports($tokens, $indexes, $global, $other, false);
    }

    /**
     * Removes the leading slash at the given indexes (when the name is not
     * already used).
     *
     * @param int[] $indexes
     *
     * @return array<string, mixed> array keys contain the names that must be imported
     */
    private function prepareImports(
        Tokens $tokens,
        array $indexes,
        array $global,
        array $other,
        bool $caseSensitive
    ): array {
        $imports = [];
        foreach ($indexes as $index) {
            $name = $tokens[$index]->getContent();
            $checkName = $caseSensitive ? $name : strtolower($name);
            if (isset($other[$checkName])) {
                continue;
            }
            if (! isset($global[$checkName])) {
                $imports[$checkName] = $name;
            } elseif (is_string($global[$checkName])) {
                $tokens[$index] = new Token([\T_STRING, $global[$checkName]]);
            }
//            $tokens->clearAt($tokens->getPrevMeaningfulToken($index));
        }

        return $imports;
    }

    /**
     * @param NamespaceUseAnalysis[] $useDeclarations
     */
    private function insertImports(Tokens $tokens, array $imports, array $useDeclarations): void
    {
        if ($useDeclarations) {
            $useDeclaration = end($useDeclarations);
            $index = $useDeclaration->getEndIndex() + 1;
        } else {
            $namespace = (new NamespacesAnalyzer())->getDeclarations($tokens)[0];
            $index = $namespace->getEndIndex() + 1;
        }
        $lineEnding = $this->whitespacesConfig->getLineEnding();
        if (! $tokens[$index]?->isWhitespace() || strpos($tokens[$index]->getContent(), "\n") === \false) {
            $tokens->insertAt($index, new Token([\T_WHITESPACE, $lineEnding]));
        }

        foreach ($imports as $typeImports) {
            foreach ($typeImports as $name) {
                // Caso especial para archivos Pest para que se agregue el import
                $items = [];
                if (! $tokens->isTokenKindFound(\T_NAMESPACE)) {
                    $items = [
                        new Token([\T_WHITESPACE, $lineEnding]),
                        new Token([\T_COMMENT, '#']),
                    ];
                }
                /** @noinspection SlowArrayOperationsInLoopInspection */
                $items = array_merge($items, [
                    new Token([\T_WHITESPACE, $lineEnding]),
                    new Token([\T_USE, 'use']),
                    new Token([\T_WHITESPACE, ' ']),
                    new Token([CT::T_FUNCTION_IMPORT, 'function']),
                    new Token([\T_WHITESPACE, ' ']),
                    // new Token([\T_NS_C, 'Safe']),
                    // new Token([\T_NS_SEPARATOR, '\\']),
                    // new Token([\T_STRING, $name]),
                    new Token([\T_NAME_FULLY_QUALIFIED, "Safe\\{$name}"]),
                    new Token(';'),
                ]);

                $tokens->insertAt($index, $items);
            }
        }
    }

    /**
     * @param NamespaceUseAnalysis[] $declarations
     * @return array<array-key, array<mixed>>
     */
    private function filterUseDeclarations(array $declarations, callable $callback, bool $caseSensitive): array
    {
        $global = [];
        $other = [];
        foreach ($declarations as $declaration) {
            if (! $callback($declaration)) {
                continue;
            }
            $fullName = ltrim($declaration->getFullName(), '\\');
            if (strpos($fullName, '\\') !== \false) {
                $name = $caseSensitive ? $declaration->getShortName() : strtolower($declaration->getShortName());
                $other[$name] = \true;

                continue;
            }
            $checkName = $caseSensitive ? $fullName : strtolower($fullName);
            $alias = $declaration->getShortName();
            $global[$checkName] = $alias === $fullName ? \true : $alias;
        }

        return [$global, $other];
    }

    /**
     * @return iterable<mixed>
     */
    private function findFunctionDeclarations(Tokens $tokens, int $start, int $end): iterable
    {
        for ($index = $start; $index <= $end; ++$index) {
            $token = $tokens[$index];
            if (! $token) {
                continue;
            }

            if ($token->isClassy()) {
                $classStart = $tokens->getNextTokenOfKind($index, ['{']);
                if ($classStart === null) {
                    continue;
                }
                $classEnd = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_CURLY_BRACE, $classStart);
                for ($index = $classStart; $index <= $classEnd; ++$index) {
                    if (! $tokens[$index]?->isGivenKind(\T_FUNCTION)) {
                        continue;
                    }
                    $methodStart = $tokens->getNextTokenOfKind($index, ['{', ';']);
                    if ($tokens[$methodStart]?->equals(';')) {
                        $index = $methodStart;

                        continue;
                    }
                    $methodEnd = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_CURLY_BRACE, $methodStart);
                    foreach ($this->findFunctionDeclarations($tokens, $methodStart, $methodEnd) as $function) {
                        (yield $function);
                    }
                    $index = $methodEnd;
                }

                continue;
            }
            if (! $token->isGivenKind(\T_FUNCTION)) {
                continue;
            }
            $index = $tokens->getNextMeaningfulToken($index);
            if (! $index) {
                continue;
            }
            if ($tokens[$index]?->isGivenKind(CT::T_RETURN_REF)) {
                $index = $tokens->getNextMeaningfulToken($index);
            }
            if ($tokens[$index]?->isGivenKind(\T_STRING)) {
                (yield $tokens[$index]->getContent());
            }
        }
    }
}
