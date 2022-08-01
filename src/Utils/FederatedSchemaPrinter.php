<?php

/**
 * This source file includes modified code from webonyx/graphql-php.
 *
 * Copyright (c) 2015-present, Webonyx, LLC.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * @copyright Copyright (c) Webonyx, LLC.
 * @license https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace Apollo\Federation\Utils;

use Apollo\Federation\Enum\DirectiveEnum;
use Apollo\Federation\FederatedSchema;
use Apollo\Federation\Types\EntityObjectType;
use Apollo\Federation\Types\EntityRefObjectType;
use GraphQL\Error\Error;
use GraphQL\Language\Printer;
use GraphQL\Type\Definition\Directive;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\EnumValueDefinition;
use GraphQL\Type\Definition\FieldArgument;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\InputObjectField;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\TypeWithFields;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\Introspection;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;
use GraphQL\Utils\Utils;

use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
use function explode;
use function implode;
use function ksort;
use function mb_strlen;
use function preg_match_all;
use function sprintf;
use function str_replace;
use function substr;

/**
 * Given an instance of Schema, prints it in GraphQL type language.
 */
class FederatedSchemaPrinter
{
    /**
     * Accepts options as a second argument:
     *    - commentDescriptions:
     *        Provide true to use preceding comments as the description.
     *
     * @param array<string, bool> $options
     *
     * @api
     */
    public static function doPrint(Schema $schema, array $options = []): string
    {
        return self::printFilteredSchema(
            $schema,
            static function (Directive $type): bool {
                return !Directive::isSpecifiedDirective($type) && !self::isFederatedDirective($type);
            },
            static function (Type $type): bool {
                return !Type::isBuiltInType($type);
            },
            $options
        );
    }

    public static function isFederatedDirective(Directive $type): bool
    {
        return \in_array($type->name, DirectiveEnum::getAll(), true);
    }

    /**
     * @param array<string, bool> $options
     */
    private static function printFilteredSchema(Schema $schema, callable $directiveFilter, callable $typeFilter, array $options): string
    {
        $directives = array_filter($schema->getDirectives(), static function (Directive $directive) use ($directiveFilter): bool {
            return $directiveFilter($directive);
        });

        $types = $schema->getTypeMap();
        ksort($types);
        $types = array_filter($types, $typeFilter);

        return sprintf(
            "%s\n",
            implode(
                "\n\n",
                array_filter(
                    array_merge(
                        array_map(static function (Directive $directive) use ($options) {
                            return self::printDirective($directive, $options);
                        }, $directives),
                        array_map(static function (Type $type) use ($options) {
                            return self::printType($type, $options);
                        }, $types)
                    )
                )
            )
        );
    }

    /**
     * @param array<string, bool> $options
     */
    private static function printDirective(Directive $directive, array $options): string
    {
        return self::printDescription($options, $directive) .
            'directive @' .
            $directive->name .
            self::printArgs($options, $directive->args) .
            ' on ' .
            implode(' | ', $directive->locations);
    }

    /**
     * @param array<string, bool> $options
     * @param Directive|EnumValueDefinition|FieldArgument|Type|object $def
     */
    private static function printDescription(array $options, $def, string $indentation = '', bool $firstInBlock = true): string
    {
        if (!isset($def->description) || !$def->description) {
            return '';
        }

        $lines = self::descriptionLines($def->description, 120 - \strlen($indentation));

        if (isset($options['commentDescriptions'])) {
            return self::printDescriptionWithComments($lines, $indentation, $firstInBlock);
        }

        $description = $indentation && !$firstInBlock ? "\n" . $indentation . '"""' : $indentation . '"""';

        // In some circumstances, a single line can be used for the description.
        if (1 === \count($lines) && mb_strlen($lines[0]) < 70 && '"' !== substr($lines[0], -1)) {
            return $description . self::escapeQuote($lines[0]) . "\"\"\"\n";
        }

        // Format a multi-line block quote to account for leading space.
        $hasLeadingSpace = isset($lines[0]) && \in_array(substr($lines[0], 0, 1), [' ', '\t'], true);

        if (!$hasLeadingSpace) {
            $description .= "\n";
        }

        $lineLength = \count($lines);

        for ($i = 0; $i < $lineLength; ++$i) {
            if (0 !== $i || !$hasLeadingSpace) {
                $description .= $indentation;
            }
            $description .= self::escapeQuote($lines[$i]) . "\n";
        }

        $description .= $indentation . "\"\"\"\n";

        return $description;
    }

    /**
     * @return string[]
     */
    private static function descriptionLines(string $description, int $maxLen): array
    {
        $lines = [];
        $rawLines = explode("\n", $description);

        foreach ($rawLines as $line) {
            if ('' === $line) {
                $lines[] = $line;
            } else {
                // For > 120 character long lines, cut at space boundaries into sublines
                // of ~80 chars.
                $sublines = self::breakLine($line, $maxLen);

                foreach ($sublines as $subline) {
                    $lines[] = $subline;
                }
            }
        }

        return $lines;
    }

    /**
     * @return string[]
     */
    private static function breakLine(string $line, int $maxLen): array
    {
        if (\strlen($line) < $maxLen + 5) {
            return [$line];
        }

        preg_match_all('/((?: |^).{15,' . ($maxLen - 40) . '}(?= |$))/', $line, $parts);

        $parts = $parts[0];

        return array_map('trim', $parts);
    }

    /**
     * @param string[] $lines
     */
    private static function printDescriptionWithComments(array $lines, string $indentation, bool $firstInBlock): string
    {
        $description = $indentation && !$firstInBlock ? "\n" : '';

        foreach ($lines as $line) {
            if ('' === $line) {
                $description .= $indentation . "#\n";
            } else {
                $description .= $indentation . '# ' . $line . "\n";
            }
        }

        return $description;
    }

    private static function escapeQuote(string $line): string
    {
        return str_replace('"""', '\\"""', $line);
    }

    /**
     * @param bool[]               $options
     * @param FieldArgument[]|null $args
     */
    private static function printArgs(array $options, $args, string $indentation = ''): string
    {
        if (!$args) {
            return '';
        }

        // If every arg does not have a description, print them on one line.
        if (
            Utils::every($args, static function ($arg) {
                return empty($arg->description);
            })
        ) {
            return '(' . implode(', ', array_map('self::printInputValue', $args)) . ')';
        }

        return sprintf(
            "(\n%s\n%s)",
            implode(
                "\n",
                array_map(
                    static function (FieldArgument $arg, $i) use ($indentation, $options): string {
                        return self::printDescription($options, $arg, '  ' . $indentation, !$i) .
                            '  ' .
                            $indentation .
                            self::printInputValue($arg);
                    },
                    $args,
                    array_keys($args)
                )
            ),
            $indentation
        );
    }

    /**
     * @param InputObjectField|FieldArgument $arg
     */
    private static function printInputValue($arg): string
    {
        $argDecl = $arg->name . ': ' . (string) $arg->getType();

        if ($arg->defaultValueExists()) {
            $argDecl .= ' = ' . Printer::doPrint(AST::astFromValue($arg->defaultValue, $arg->getType()));
        }

        return $argDecl;
    }

    /**
     * @param array<string, bool> $options
     */
    public static function printType(Type $type, array $options = []): string
    {
        if ($type instanceof ScalarType) {
            if (FederatedSchema::RESERVED_TYPE_ANY !== $type->name) {
                return self::printScalar($type, $options);
            }

            return '';
        }

        if ($type instanceof EntityObjectType || $type instanceof EntityRefObjectType) {
            return self::printEntityObject($type, $options);
        }

        if ($type instanceof ObjectType) {
            if (FederatedSchema::RESERVED_TYPE_SERVICE !== $type->name) {
                return self::printObject($type, $options);
            }

            return '';
        }

        if ($type instanceof InterfaceType) {
            return self::printInterface($type, $options);
        }

        if ($type instanceof UnionType) {
            if (FederatedSchema::RESERVED_TYPE_ENTITY !== $type->name) {
                return self::printUnion($type, $options);
            }

            return '';
        }

        if ($type instanceof EnumType) {
            return self::printEnum($type, $options);
        }

        if ($type instanceof InputObjectType) {
            return self::printInputObject($type, $options);
        }

        throw new Error(sprintf('Unknown type: %s.', Utils::printSafe($type)));
    }

    /**
     * @param array<string, bool> $options
     */
    private static function printScalar(ScalarType $type, array $options): string
    {
        return sprintf('%sscalar %s', self::printDescription($options, $type), $type->name);
    }

    /**
     * @param array<string, bool> $options
     */
    private static function printObject(ObjectType $type, array $options): string
    {
        if (empty($type->getFields())) {
            return '';
        }

        $interfaces = $type->getInterfaces();
        $implementedInterfaces = !empty($interfaces)
            ? ' implements ' .
                implode(
                    ' & ',
                    array_map(static function ($i) {
                        return $i->name;
                    }, $interfaces)
                )
            : '';

        $queryExtends = \in_array($type->name, [FederatedSchema::RESERVED_TYPE_QUERY, FederatedSchema::RESERVED_TYPE_MUTATION], true)
            ? 'extend '
            : '';

        return self::printDescription($options, $type) .
            sprintf(
                "%stype %s%s {\n%s\n}",
                $queryExtends,
                $type->name,
                $implementedInterfaces,
                self::printFields($options, $type)
            );
    }

    /**
     * @param array<string, bool> $options
     */
    private static function printEntityObject(EntityObjectType $type, array $options): string
    {
        $interfaces = $type->getInterfaces();
        $implementedInterfaces = !empty($interfaces)
            ? ' implements ' .
                implode(
                    ' & ',
                    array_map(static function ($i) {
                        return $i->name;
                    }, $interfaces)
                )
            : '';

        $keyDirective = '';

        foreach ($type->getKeyFields() as $keyField) {
            $keyDirective = $keyDirective . sprintf(' @key(fields: "%s")', self::printKeyFields($keyField));
        }

        $isEntityRef = $type instanceof EntityRefObjectType;
        $extends = $isEntityRef ? 'extend ' : '';

        return self::printDescription($options, $type) .
            sprintf(
                "%stype %s%s%s {\n%s\n}",
                $extends,
                $type->name,
                $implementedInterfaces,
                $keyDirective,
                self::printFields($options, $type)
            );
    }

    /**
     * @param array<string, bool> $options
     * @param EntityObjectType|InterfaceType|ObjectType $type
     */
    private static function printFields(array $options, TypeWithFields $type): string
    {
        $fields = array_values($type->getFields());

        if (FederatedSchema::RESERVED_TYPE_QUERY === $type->name) {
            $fields = array_filter($fields, static function (FieldDefinition $field): bool {
                return FederatedSchema::RESERVED_FIELD_SERVICE !== $field->name
                    && FederatedSchema::RESERVED_FIELD_ENTITIES !== $field->name;
            });
        }

        return implode(
            "\n",
            array_map(
                static function (FieldDefinition $f, $i) use ($options) {
                    return self::printDescription($options, $f, '  ', !$i) .
                        '  ' .
                        $f->name .
                        self::printArgs($options, $f->args, '  ') .
                        ': ' .
                        (string) $f->getType() .
                        self::printDeprecated($f) .
                        ' ' .
                        self::printFieldFederatedDirectives($f);
                },
                $fields,
                array_keys($fields)
            )
        );
    }

    /**
     * @param EnumValueDefinition|FieldDefinition $fieldOrEnumVal
     */
    private static function printDeprecated($fieldOrEnumVal): string
    {
        $reason = $fieldOrEnumVal->deprecationReason;
        if (empty($reason)) {
            return '';
        }
        if ('' === $reason || Directive::DEFAULT_DEPRECATION_REASON === $reason) {
            return ' @deprecated';
        }

        return ' @deprecated(reason: ' . Printer::doPrint(AST::astFromValue($reason, Type::string())) . ')';
    }

    private static function printFieldFederatedDirectives(FieldDefinition $field): string
    {
        $directives = [];

        if (isset($field->config[EntityObjectType::FIELD_DIRECTIVE_IS_EXTERNAL])
            && true === $field->config[EntityObjectType::FIELD_DIRECTIVE_IS_EXTERNAL]
        ) {
            $directives[] = '@external';
        }

        if (isset($field->config[EntityObjectType::FIELD_DIRECTIVE_PROVIDES])) {
            $directives[] = sprintf('@provides(fields: "%s")', self::printKeyFields($field->config[EntityObjectType::FIELD_DIRECTIVE_PROVIDES]));
        }

        if (isset($field->config[EntityObjectType::FIELD_DIRECTIVE_REQUIRES])) {
            $directives[] = sprintf('@requires(fields: "%s")', self::printKeyFields($field->config[EntityObjectType::FIELD_DIRECTIVE_REQUIRES]));
        }

        return implode(' ', $directives);
    }

    /**
     * @param array<string, bool> $options
     */
    private static function printInterface(InterfaceType $type, array $options): string
    {
        return self::printDescription($options, $type) .
            sprintf("interface %s {\n%s\n}", $type->name, self::printFields($options, $type));
    }

    /**
     * Print simple and compound primary key fields
     * {@see https://www.apollographql.com/docs/federation/v1/entities#compound-primary-keys }.
     *
     * @param string|array<string|int, mixed> $keyFields
     */
    private static function printKeyFields($keyFields): string
    {
        $parts = [];
        foreach (((array) $keyFields) as $index => $keyField) {
            if (\is_string($keyField)) {
                $parts[] = $keyField;
            } elseif (\is_array($keyField)) {
                $parts[] = sprintf('%s { %s }', $index, self::printKeyFields($keyField));
            } else {
                throw new \InvalidArgumentException('Invalid keyField config');
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<string, bool> $options
     */
    private static function printUnion(UnionType $type, array $options): string
    {
        return self::printDescription($options, $type) .
            sprintf('union %s = %s', $type->name, implode(' | ', $type->getTypes()));
    }

    /**
     * @param array<string, bool> $options
     */
    private static function printEnum(EnumType $type, array $options): string
    {
        return self::printDescription($options, $type) .
            sprintf("enum %s {\n%s\n}", $type->name, self::printEnumValues($type->getValues(), $options));
    }

    /**
     * @param EnumValueDefinition[] $values
     * @param array<string, bool> $options
     */
    private static function printEnumValues(array $values, array $options): string
    {
        return implode(
            "\n",
            array_map(
                static function ($value, $i) use ($options) {
                    return self::printDescription($options, $value, '  ', !$i) .
                        '  ' .
                        $value->name .
                        self::printDeprecated($value);
                },
                $values,
                array_keys($values)
            )
        );
    }

    /**
     * @param array<string, bool> $options
     */
    private static function printInputObject(InputObjectType $type, array $options): string
    {
        $fields = array_values($type->getFields());

        return self::printDescription($options, $type) .
            sprintf(
                "input %s {\n%s\n}",
                $type->name,
                implode(
                    "\n",
                    array_map(
                        static function ($f, $i) use ($options) {
                            return self::printDescription($options, $f, '  ', !$i) . '  ' . self::printInputValue($f);
                        },
                        $fields,
                        array_keys($fields)
                    )
                )
            );
    }

    /**
     * @param array<string, bool> $options
     *
     * @api
     */
    public static function printIntrospectionSchema(Schema $schema, array $options = []): string
    {
        return self::printFilteredSchema(
            $schema,
            [Directive::class, 'isSpecifiedDirective'],
            [Introspection::class, 'isIntrospectionType'],
            $options
        );
    }
}
