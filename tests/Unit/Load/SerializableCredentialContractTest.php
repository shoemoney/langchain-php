<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Load;

use LangChain\Schema\Document;
use LangChain\Load\Load;
use LangChain\Load\Serializable;
use LangChain\Messages\HumanMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fixed JSON-boundary projections of complete pinned LC serializable/map_keys/validation modules,
 * with imports/types stripped (not a full module build). Numeric ordering/nested undefined excluded.
 * pin 17b6bba8343bdd3f3dfc5c59578e0b72337ec740; Serializable source 2f422908b4d447bcff8d143849f80dc14b4a65ee31fd972909ff30ad617ad612.
 * R2 projection sha256 3f482765b31ddd77c0c473e53a3d198dc63a181d5a01ca2ead97bd5c00c2d6d0.
 * Original harness 24105ef37204e5fde964f9debb34cab94b2c9f5953798b593bd6d21ad591a494.
 * Empty PHP [] means source object/no-arg; object constructor bags are a typed unsupported adaptation.
 */
#[CoversClass(Serializable::class)]
final class SerializableCredentialContractTest extends TestCase
{
    private const SOURCE_ROWS = <<<'JSON'
{
  "pin": "17b6bba8343bdd3f3dfc5c59578e0b72337ec740",
  "rows": [
    {
      "label": "empty-implicit",
      "captured": {},
      "json": {
        "lc": 1,
        "type": "constructor",
        "id": [
          "SYNTH_CORE",
          "Plain"
        ],
        "kwargs": {}
      },
      "jsonText": "{\"lc\":1,\"type\":\"constructor\",\"id\":[\"SYNTH_CORE\",\"Plain\"],\"kwargs\":{}}",
      "kwargsObject": true,
      "jsonBoundaryUndefinedDrop": false,
      "callerUnchanged": true
    },
    {
      "label": "empty-object",
      "input": {},
      "captured": {},
      "json": {
        "lc": 1,
        "type": "constructor",
        "id": [
          "SYNTH_CORE",
          "Plain"
        ],
        "kwargs": {}
      },
      "jsonText": "{\"lc\":1,\"type\":\"constructor\",\"id\":[\"SYNTH_CORE\",\"Plain\"],\"kwargs\":{}}",
      "kwargsObject": true,
      "jsonBoundaryUndefinedDrop": false,
      "callerUnchanged": true
    },
    {
      "label": "empty-null-constructor",
      "input": null,
      "captured": {},
      "json": {
        "lc": 1,
        "type": "constructor",
        "id": [
          "SYNTH_CORE",
          "Plain"
        ],
        "kwargs": {}
      },
      "jsonText": "{\"lc\":1,\"type\":\"constructor\",\"id\":[\"SYNTH_CORE\",\"Plain\"],\"kwargs\":{}}",
      "kwargsObject": true,
      "jsonBoundaryUndefinedDrop": false,
      "callerUnchanged": true
    },
    {
      "label": "nonempty-list",
      "input": [
        "SYNTH_LIST"
      ],
      "captured": [
        "SYNTH_LIST"
      ],
      "json": {
        "lc": 1,
        "type": "not_implemented",
        "id": [
          "SYNTH_CORE",
          "Plain"
        ]
      },
      "jsonText": "{\"lc\":1,\"type\":\"not_implemented\",\"id\":[\"SYNTH_CORE\",\"Plain\"]}",
      "kwargsObject": false,
      "jsonBoundaryUndefinedDrop": false,
      "callerUnchanged": true
    },
    {
      "label": "nonarray-string",
      "input": "SYNTH_PRIMITIVE",
      "captured": "SYNTH_PRIMITIVE",
      "json": {
        "lc": 1,
        "type": "not_implemented",
        "id": [
          "SYNTH_CORE",
          "Plain"
        ]
      },
      "jsonText": "{\"lc\":1,\"type\":\"not_implemented\",\"id\":[\"SYNTH_CORE\",\"Plain\"]}",
      "kwargsObject": false,
      "jsonBoundaryUndefinedDrop": false,
      "callerUnchanged": true
    },
    {
      "label": "default-false",
      "input": {},
      "captured": {},
      "json": {
        "lc": 1,
        "type": "not_implemented",
        "id": [
          "SYNTH_CORE",
          "SourceDefault"
        ]
      },
      "jsonText": "{\"lc\":1,\"type\":\"not_implemented\",\"id\":[\"SYNTH_CORE\",\"SourceDefault\"]}",
      "kwargsObject": false,
      "jsonBoundaryUndefinedDrop": false,
      "callerUnchanged": true
    },
    {
      "label": "explicit-true",
      "input": {
        "content": "SYNTH_CONTENT"
      },
      "captured": {
        "content": "SYNTH_CONTENT"
      },
      "json": {
        "lc": 1,
        "type": "constructor",
        "id": [
          "SYNTH_CORE",
          "Plain"
        ],
        "kwargs": {
          "content": "SYNTH_CONTENT"
        }
      },
      "jsonText": "{\"lc\":1,\"type\":\"constructor\",\"id\":[\"SYNTH_CORE\",\"Plain\"],\"kwargs\":{\"content\":\"SYNTH_CONTENT\"}}",
      "kwargsObject": true,
      "jsonBoundaryUndefinedDrop": false,
      "callerUnchanged": true
    },
    {
      "label": "whitelist-readd",
      "input": {
        "content": "SYNTH_CONTENT",
        "apiKey": "SYNTH_CAPTURED",
        "extra": "SYNTH_CAPTURED_EXTRA",
        "discard": "SYNTH_CAPTURED_DISCARD"
      },
      "captured": {
        "content": "SYNTH_CONTENT"
      },
      "json": {
        "lc": 1,
        "type": "constructor",
        "id": [
          "SYNTH_CORE",
          "KeysReadd"
        ],
        "kwargs": {
          "content": "SYNTH_CONTENT",
          "extra": "SYNTH_ATTRIBUTE",
          "api_key": {
            "lc": 1,
            "type": "secret",
            "id": [
              "SYNTH_READD_SECRET"
            ]
          }
        }
      },
      "jsonText": "{\"lc\":1,\"type\":\"constructor\",\"id\":[\"SYNTH_CORE\",\"KeysReadd\"],\"kwargs\":{\"content\":\"SYNTH_CONTENT\",\"extra\":\"SYNTH_ATTRIBUTE\",\"api_key\":{\"lc\":1,\"type\":\"secret\",\"id\":[\"SYNTH_READD_SECRET\"]}}}",
      "kwargsObject": true,
      "jsonBoundaryUndefinedDrop": true,
      "callerUnchanged": true
    },
    {
      "label": "excluded-later-property",
      "input": {
        "content": "SYNTH_CONTENT",
        "discard": "SYNTH_CAPTURED_DISCARD"
      },
      "captured": {
        "content": "SYNTH_CONTENT"
      },
      "json": {
        "lc": 1,
        "type": "constructor",
        "id": [
          "SYNTH_CORE",
          "KeysReadd"
        ],
        "kwargs": {
          "content": "SYNTH_CONTENT",
          "extra": "SYNTH_ATTRIBUTE"
        }
      },
      "jsonText": "{\"lc\":1,\"type\":\"constructor\",\"id\":[\"SYNTH_CORE\",\"KeysReadd\"],\"kwargs\":{\"content\":\"SYNTH_CONTENT\",\"extra\":\"SYNTH_ATTRIBUTE\"}}",
      "kwargsObject": true,
      "jsonBoundaryUndefinedDrop": true,
      "callerUnchanged": true
    },
    {
      "label": "receiver-inherited-parent-winner",
      "input": {
        "apiKey": "SYNTH_CAPTURED",
        "current": "SYNTH_CAPTURED_CURRENT"
      },
      "captured": {
        "apiKey": "SYNTH_CAPTURED",
        "current": "SYNTH_CAPTURED_CURRENT"
      },
      "json": {
        "lc": 1,
        "type": "constructor",
        "id": [
          "SYNTH_CORE",
          "InheritedGrandchild"
        ],
        "kwargs": {
          "parent_wire": {
            "lc": 1,
            "type": "secret",
            "id": [
              "SYNTH_PARENT"
            ]
          },
          "current": "SYNTH_RECEIVER_CURRENT",
          "derived": "SYNTH_RECEIVER_CURRENT",
          "origin": "SYNTH_PARENT"
        }
      },
      "jsonText": "{\"lc\":1,\"type\":\"constructor\",\"id\":[\"SYNTH_CORE\",\"InheritedGrandchild\"],\"kwargs\":{\"parent_wire\":{\"lc\":1,\"type\":\"secret\",\"id\":[\"SYNTH_PARENT\"]},\"current\":\"SYNTH_RECEIVER_CURRENT\",\"derived\":\"SYNTH_RECEIVER_CURRENT\",\"origin\":\"SYNTH_PARENT\"}}",
      "kwargsObject": true,
      "jsonBoundaryUndefinedDrop": false,
      "callerUnchanged": true
    },
    {
      "label": "numeric-js-key-order",
      "input": {
        "2": "SYNTH_TWO",
        "b": "SYNTH_B",
        "a": "SYNTH_A"
      },
      "captured": {
        "2": "SYNTH_TWO",
        "b": "SYNTH_B",
        "a": "SYNTH_A"
      },
      "json": {
        "lc": 1,
        "type": "constructor",
        "id": [
          "SYNTH_CORE",
          "Plain"
        ],
        "kwargs": {
          "2": "SYNTH_TWO",
          "b": "SYNTH_B",
          "a": "SYNTH_A"
        }
      },
      "jsonText": "{\"lc\":1,\"type\":\"constructor\",\"id\":[\"SYNTH_CORE\",\"Plain\"],\"kwargs\":{\"2\":\"SYNTH_TWO\",\"b\":\"SYNTH_B\",\"a\":\"SYNTH_A\"}}",
      "kwargsObject": true,
      "jsonBoundaryUndefinedDrop": false,
      "callerUnchanged": true
    },
    {
      "label": "live-object-secret-path",
      "json": {
        "lc": 1,
        "type": "constructor",
        "id": [
          "SYNTH_CORE",
          "LiveOwner"
        ],
        "kwargs": {
          "live": {
            "lc_serializable": true,
            "lc_kwargs": {
              "token": "SYNTH_LIVE_TOKEN",
              "sibling": {
                "lc": 1,
                "type": "secret",
                "id": [
                  "SYNTH_LITERAL"
                ]
              }
            },
            "lc_namespace": [
              "SYNTH_CORE"
            ],
            "token": {
              "lc": 1,
              "type": "secret",
              "id": [
                "SYNTH_LIVE_SECRET"
              ]
            },
            "sibling": {
              "lc": 1,
              "type": "secret",
              "id": [
                "SYNTH_LITERAL"
              ]
            }
          }
        }
      },
      "liveRetainedIdentity": true,
      "callerUnchanged": true,
      "tokenUnchanged": true
    },
    {
      "label": "refresh-before-repeated-hooks",
      "json": {
        "lc": 1,
        "type": "constructor",
        "id": [
          "SYNTH_CORE",
          "TimingGrandchild"
        ],
        "kwargs": {
          "current": "SYNTH_BEFORE_HOOK",
          "derived": "SYNTH_PARENT_AFTER_HOOK"
        }
      },
      "events": [
        "C.alias:SYNTH_BEFORE_HOOK",
        "C.secret:SYNTH_CHILD_AFTER_HOOK",
        "C.attribute:SYNTH_CHILD_AFTER_HOOK",
        "C.alias:SYNTH_CHILD_AFTER_HOOK",
        "C.secret:SYNTH_CHILD_AFTER_HOOK",
        "C.attribute:SYNTH_CHILD_AFTER_HOOK",
        "P.alias:SYNTH_CHILD_AFTER_HOOK",
        "P.secret:SYNTH_PARENT_AFTER_HOOK",
        "P.attribute:SYNTH_PARENT_AFTER_HOOK"
      ]
    }
  ]
}
JSON;

    // R1 review repair and retained seams: actual complete source bodies, module-strict harness.
    private const SOURCE_REPAIR_ROWS = <<<'JSON'
{
  "pin": "17b6bba8343bdd3f3dfc5c59578e0b72337ec740",
  "moduleStrict": true,
  "rows": [
    {
      "label": "missing-configuration-array",
      "json": {
        "lc": 1,
        "type": "constructor",
        "id": [
          "SYNTH_CORE",
          "Configuration"
        ],
        "kwargs": {
          "content": "SYNTH_C",
          "configuration": {
            "apiKey": {
              "lc": 1,
              "type": "secret",
              "id": [
                "SYNTH_CFG"
              ]
            }
          }
        }
      },
      "jsonText": "{\"lc\":1,\"type\":\"constructor\",\"id\":[\"SYNTH_CORE\",\"Configuration\"],\"kwargs\":{\"content\":\"SYNTH_C\",\"configuration\":{\"apiKey\":{\"lc\":1,\"type\":\"secret\",\"id\":[\"SYNTH_CFG\"]}}}}",
      "configurationRawKeyUnchanged": true
    },
    {
      "label": "missing-configuration-object",
      "json": {
        "lc": 1,
        "type": "constructor",
        "id": [
          "SYNTH_CORE",
          "Configuration"
        ],
        "kwargs": {
          "content": "SYNTH_C",
          "configuration": {
            "apiKey": {
              "lc": 1,
              "type": "secret",
              "id": [
                "SYNTH_CFG"
              ]
            }
          }
        }
      },
      "jsonText": "{\"lc\":1,\"type\":\"constructor\",\"id\":[\"SYNTH_CORE\",\"Configuration\"],\"kwargs\":{\"content\":\"SYNTH_C\",\"configuration\":{\"apiKey\":{\"lc\":1,\"type\":\"secret\",\"id\":[\"SYNTH_CFG\"]}}}}",
      "configurationRawKeyUnchanged": true
    },
    {
      "label": "unported-partial-depth-break",
      "json": {
        "lc": 1,
        "type": "constructor",
        "id": [
          "SYNTH_CORE",
          "Partial"
        ],
        "kwargs": {
          "b": {
            "lc": 1,
            "type": "secret",
            "id": [
              "SYNTH_AB"
            ]
          }
        }
      },
      "jsonText": "{\"lc\":1,\"type\":\"constructor\",\"id\":[\"SYNTH_CORE\",\"Partial\"],\"kwargs\":{\"b\":{\"lc\":1,\"type\":\"secret\",\"id\":[\"SYNTH_AB\"]}}}"
    },
    {
      "label": "unported-js-unicode-whitespace",
      "json": {
        "lc": 1,
        "type": "constructor",
        "id": [
          "SYNTH_CORE",
          "Plain"
        ],
        "kwargs": {
          "a_b": "SYNTH_SPACE"
        }
      },
      "jsonText": "{\"lc\":1,\"type\":\"constructor\",\"id\":[\"SYNTH_CORE\",\"Plain\"],\"kwargs\":{\"a_b\":\"SYNTH_SPACE\"}}"
    },
    {
      "label": "unported-int-coerced-whitelist",
      "captured": {
        "2": "SYNTH_NUMERIC"
      },
      "json": {
        "lc": 1,
        "type": "constructor",
        "id": [
          "SYNTH_CORE",
          "NumericKeys"
        ],
        "kwargs": {
          "2": "SYNTH_NUMERIC"
        }
      },
      "jsonText": "{\"lc\":1,\"type\":\"constructor\",\"id\":[\"SYNTH_CORE\",\"NumericKeys\"],\"kwargs\":{\"2\":\"SYNTH_NUMERIC\"}}"
    },
    {
      "label": "unported-scalar-path",
      "errorName": "TypeError",
      "message": "Cannot create property 'apiKey' on string 'SYNTH_SCALAR'"
    }
  ]
}
JSON;

    // R2 code-review M1b: exact full-module source envelopes, module-strict offline oracle.
    private const SOURCE_READ_GUARD_ROWS = <<<'JSON'
{
  "pin": "17b6bba8343bdd3f3dfc5c59578e0b72337ec740",
  "moduleStrict": true,
  "rows": [
    {
      "label": "B",
      "json": {
        "lc": 1,
        "type": "constructor",
        "id": [
          "SYNTH_CORE",
          "CfgAttr"
        ],
        "kwargs": {
          "content": "SYNTH_C"
        }
      },
      "jsonText": "{\"lc\":1,\"type\":\"constructor\",\"id\":[\"SYNTH_CORE\",\"CfgAttr\"],\"kwargs\":{\"content\":\"SYNTH_C\"}}",
      "repeatStable": true
    },
    {
      "label": "C",
      "json": {
        "lc": 1,
        "type": "constructor",
        "id": [
          "SYNTH_CORE",
          "Cfg"
        ],
        "kwargs": {
          "content": "SYNTH_C"
        }
      },
      "jsonText": "{\"lc\":1,\"type\":\"constructor\",\"id\":[\"SYNTH_CORE\",\"Cfg\"],\"kwargs\":{\"content\":\"SYNTH_C\"}}",
      "repeatStable": true
    },
    {
      "label": "A",
      "json": {
        "lc": 1,
        "type": "constructor",
        "id": [
          "SYNTH_CORE",
          "CfgAttr"
        ],
        "kwargs": {
          "content": "SYNTH_C",
          "configuration": {
            "name": {
              "lc": 1,
              "type": "secret",
              "id": [
                "SYNTH_N"
              ]
            }
          }
        }
      },
      "jsonText": "{\"lc\":1,\"type\":\"constructor\",\"id\":[\"SYNTH_CORE\",\"CfgAttr\"],\"kwargs\":{\"content\":\"SYNTH_C\",\"configuration\":{\"name\":{\"lc\":1,\"type\":\"secret\",\"id\":[\"SYNTH_N\"]}}}}",
      "repeatStable": true
    },
    {
      "label": "E",
      "json": {
        "lc": 1,
        "type": "constructor",
        "id": [
          "SYNTH_CORE",
          "Cfg"
        ],
        "kwargs": {
          "content": "SYNTH_C"
        }
      },
      "jsonText": "{\"lc\":1,\"type\":\"constructor\",\"id\":[\"SYNTH_CORE\",\"Cfg\"],\"kwargs\":{\"content\":\"SYNTH_C\"}}",
      "repeatStable": true
    },
    {
      "label": "F",
      "json": {
        "lc": 1,
        "type": "constructor",
        "id": [
          "SYNTH_CORE",
          "CfgKeyAttr"
        ],
        "kwargs": {
          "content": "SYNTH_C"
        }
      },
      "jsonText": "{\"lc\":1,\"type\":\"constructor\",\"id\":[\"SYNTH_CORE\",\"CfgKeyAttr\"],\"kwargs\":{\"content\":\"SYNTH_C\"}}",
      "repeatStable": true
    }
  ]
}
JSON;

    private static function readGuardRow(string $label): array
    {
        foreach (json_decode(self::SOURCE_READ_GUARD_ROWS, true, 512, JSON_THROW_ON_ERROR)['rows'] as $row) {
            if ($row['label'] === $label) {
                return $row;
            }
        }
        throw new \LogicException('Unknown fixed read-guard row.');
    }

    private static function repairRow(string $label): array
    {
        foreach (json_decode(self::SOURCE_REPAIR_ROWS, true, 512, JSON_THROW_ON_ERROR)['rows'] as $row) {
            if ($row['label'] === $label) {
                return $row;
            }
        }
        throw new \LogicException('Unknown fixed repair row.');
    }

    private static function row(string $label): array
    {
        foreach (json_decode(self::SOURCE_ROWS, true, 512, JSON_THROW_ON_ERROR)['rows'] as $row) {
            if ($row['label'] === $label) {
                return $row;
            }
        }
        throw new \LogicException('Unknown fixed source row.');
    }

    private static function marker(string $id): array
    {
        return ['lc' => 1, 'type' => 'secret', 'id' => [$id]];
    }

    public function testEmptyBagAcrossAllPublicPathsAndNeverCapturedIsIntentionallyEmpty(): void
    {
        foreach ([null, []] as $input) {
            $owner = new S1CFixture($input);
            foreach ([$owner->toSerializedConstructor(), $owner->toJson(), $owner->jsonSerialize(), $owner] as $value) {
                self::assertSame(self::row('empty-implicit')['jsonText'], json_encode($value, JSON_THROW_ON_ERROR));
            }
            self::assertInstanceOf(\stdClass::class, $owner->toJson()['kwargs']);
            self::assertSame([], $owner->kwargs());
            self::assertSame(1, $owner->captureCalls);
        }
        $owner = new S1CNeverCaptured();
        self::assertSame(self::row('empty-implicit')['jsonText'], json_encode($owner, JSON_THROW_ON_ERROR));
        self::assertSame(['content' => 'SYNTH_LEGACY_CAPTURE'], $owner->kwargs());
    }


    public static function fixedEligibilityRows(): iterable
    {
        foreach (['empty-implicit', 'empty-object', 'empty-null-constructor', 'nonempty-list', 'nonarray-string', 'explicit-true'] as $label) {
            $row = self::row($label);
            yield $label => [$row['input'] ?? null, $row['jsonText']];
        }
    }

    #[DataProvider('fixedEligibilityRows')]
    public function testFixedSourceEligibilityEnvelope(mixed $input, string $expectedJson): void
    {
        self::assertSame($expectedJson, json_encode(new S1CFixture($input), JSON_THROW_ON_ERROR));
        self::assertSame(self::row('default-false')['json'], (new S1CDefaultEligibility())->toJson());
    }

    public static function unsupportedInputs(): iterable
    {
        yield 'list' => [['SYNTH_LIST'], true];
        yield 'string' => ['SYNTH_PRIMITIVE', true];
        yield 'object bag typed adaptation' => [(object) ['content' => 'SYNTH_OBJECT'], true];
        yield 'independent eligibility' => [['content' => 'SYNTH_CONTENT'], false];
    }

    #[DataProvider('unsupportedInputs')]
    public function testUnsupportedInputsDoNotEvaluateHooks(mixed $input, bool $eligible): void
    {
        $owner = new S1CFixture($input, eligible: $eligible);
        self::assertSame(['lc' => 1, 'type' => 'not_implemented', 'id' => ['SYNTH_CORE', 'Plain']], $owner->toJson());
        self::assertSame(0, $owner->hookCalls);
        self::assertSame(1, $owner->eligibilityCalls); // Eligibility evaluates before the not_implemented gate.
        self::assertTrue(Serializable::lcSerializable());
        self::assertSame('not_implemented', (new S1CDefaultEligibility())->toJson()['type']);
        self::assertSame($input ?? [], Serializable::captureSourceContractBag($input));
    }

    public function testCaptureWhitelistOnceAllowsSecretAndAttributeReaddition(): void
    {
        foreach (['whitelist-readd', 'excluded-later-property'] as $label) {
            $row = self::row($label);
            $owner = new S1CKeysFixture($row['input'], ['content'], secrets: ['apiKey' => 'SYNTH_READD_SECRET'],
                attributes: ['extra' => 'SYNTH_ATTRIBUTE', 'drop' => Serializable::sourceContractUndefined()]);
            $owner->discard = 'SYNTH_CHANGED_EXCLUDED';
            if ($label === 'whitelist-readd') {
                $owner->apiKey = 'SYNTH_CURRENT';
            }
            self::assertSame($row['jsonText'], json_encode($owner, JSON_THROW_ON_ERROR));
            self::assertSame($row['jsonText'], json_encode($owner, JSON_THROW_ON_ERROR));
            self::assertSame(1, $owner->captureCalls);
            self::assertSame($row['input'], $owner->kwargs());
            self::assertSame($row['captured'], Serializable::captureSourceContractBag($row['input'], ['content']));
        }
    }

    public static function whitelistRejects(): iterable
    {
        yield [['SYNTH_LIST']];
        yield ['SYNTH_STRING'];
        yield [(object) ['content' => 'SYNTH_OBJECT']];
    }

    #[DataProvider('whitelistRejects')]
    public function testWhitelistTypedRejection(mixed $bag): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Serializable::captureSourceContractBag($bag, ['content']);
    }

    public function testPublicInitializedRefreshExcludesPrivateProtectedUninitializedAndMagic(): void
    {
        $owner = new S1CFixture(['content' => 'SYNTH_CAPTURED', 'apiKey' => 'SYNTH_CAPTURED_KEY',
            'hidden' => 'SYNTH_CAPTURED_HIDDEN', 'privateSecret' => 'SYNTH_CAPTURED_PRIVATE']);
        $owner->content = null;
        $owner->addedLater = 'SYNTH_NOT_CAPTURED';
        self::assertSame(['content' => null, 'api_key' => 'SYNTH_CAPTURED_KEY',
            'hidden' => 'SYNTH_CAPTURED_HIDDEN', 'private_secret' => 'SYNTH_CAPTURED_PRIVATE'], $owner->toJson()['kwargs']);
        $state = Serializable::sourceContractPublicState($owner);
        self::assertArrayNotHasKey('apiKey', $state);
        self::assertArrayNotHasKey('hidden', $state);
        self::assertArrayNotHasKey('privateSecret', $state);
        self::assertSame(0, $owner->magicCalls);
        self::assertSame('SYNTH_NOT_CAPTURED', $state['addedLater']);
    }

    public function testParentWinsWithActualReceiverAndRepeatedInheritedHookTiming(): void
    {
        $row = self::row('receiver-inherited-parent-winner');
        $owner = new S1CInheritedGrandchild($row['input']);
        $owner->apiKey = 'SYNTH_CURRENT';
        $owner->current = 'SYNTH_RECEIVER_CURRENT';
        self::assertSame($row['jsonText'], json_encode($owner, JSON_THROW_ON_ERROR));
        $timing = new S1CTimingGrandchild(['current' => 'SYNTH_CAPTURED_CURRENT']);
        $timing->current = 'SYNTH_BEFORE_HOOK';
        self::assertSame(self::row('refresh-before-repeated-hooks')['json'], json_decode(json_encode($timing, JSON_THROW_ON_ERROR), true));
        self::assertSame(self::row('refresh-before-repeated-hooks')['events'], $timing->events);
        self::assertSame('SYNTH_PARENT_AFTER_HOOK', $timing->current);
        $timing->events = [];
        $timing->current = 'SYNTH_BEFORE_HOOK';
        $timing->toSerializedConstructor();
        self::assertSame(self::row('refresh-before-repeated-hooks')['events'], $timing->events);
    }

    public static function definedSecretValues(): iterable
    {
        yield 'null' => [null];
        yield 'false' => [false];
        yield 'zero' => [0];
        yield 'empty string' => [''];
        yield 'empty array JS truthy' => [[]];
        yield 'string zero JS truthy' => ['0'];
    }

    #[DataProvider('definedSecretValues')]
    public function testDefinedFalsySecretValuesAndInclusionFallback(mixed $value): void
    {
        $owner = new S1CFixture([], secrets: ['apiKey' => 'SYNTH_S']);
        $owner->apiKey = $value;
        self::assertSame(['api_key' => self::marker('SYNTH_S')], $owner->toJson()['kwargs']);
        self::assertSame($value, $owner->apiKey);
        $owner = new S1CFixture(['apiKey' => 'SYNTH_CAPTURED'], secrets: ['apiKey' => 'SYNTH_S'],
            attributes: ['apiKey' => Serializable::sourceContractUndefined()]);
        $owner->apiKey = $value;
        self::assertSame(['api_key' => self::marker('SYNTH_S')], $owner->toJson()['kwargs']);
    }

    public function testUndefinedIsDroppedAfterAliasLastWriteAndLiteralStringIsOrdinary(): void
    {
        $u = Serializable::sourceContractUndefined();
        self::assertInstanceOf(\UnitEnum::class, $u);
        self::assertSame($u, Serializable::sourceContractUndefined());
        $owner = new S1CFixture(['target' => 'SYNTH_T'], aliases: ['other' => 'target'], attributes: ['other' => $u]);
        self::assertSame('{}', json_encode($owner->toJson()['kwargs'], JSON_THROW_ON_ERROR));
        $owner = new S1CFixture(['other' => 'SYNTH_O', 'target' => 'SYNTH_T'], aliases: ['other' => 'target'], attributes: ['other' => $u]);
        self::assertSame(['target' => 'SYNTH_T'], $owner->toJson()['kwargs']);
        $owner = new S1CFixture(['apiKey' => 'SYNTH_CAPTURED'], secrets: ['apiKey' => 'SYNTH_S'], attributes: ['apiKey' => $u]);
        self::assertSame('{}', json_encode($owner->toJson()['kwargs'], JSON_THROW_ON_ERROR));
        $owner->apiKey = $u;
        self::assertSame('{}', json_encode($owner->toJson()['kwargs'], JSON_THROW_ON_ERROR));
        self::assertSame(['value' => 'undefined'], (new S1CFixture(['value' => 'undefined']))->toJson()['kwargs']);
    }

    public function testOutboundAliasTruthinessUnicodeAndAcronymBoundaries(): void
    {
        $owner = new S1CFixture(['zeroAlias' => 'SYNTH_ZERO_ALIAS', 'emptyAlias' => 'SYNTH_EMPTY_ALIAS',
            'HTTPServer' => 'SYNTH_HTTP', 'ÄHTTPServer' => 'SYNTH_UNICODE', 'HTTP2Value' => 'SYNTH_DIGIT', 'a___b' => 'SYNTH_SEPARATOR'],
            aliases: ['zeroAlias' => '0', 'emptyAlias' => '']);
        self::assertSame('{"0":"SYNTH_ZERO_ALIAS","empty_alias":"SYNTH_EMPTY_ALIAS","http_server":"SYNTH_HTTP","ähttp_server":"SYNTH_UNICODE","htt_p2_value":"SYNTH_DIGIT","a_b":"SYNTH_SEPARATOR"}',
            json_encode($owner->toJson()['kwargs'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function testAttributesEscapeBeforeTrustedMarkersAndLegacyFilterIsBypassed(): void
    {
        $literal = self::marker('SYNTH_LITERAL');
        $owner = new S1CFixture(['apiKey' => 'SYNTH_CAPTURED'], secrets: ['apiKey' => 'SYNTH_S'],
            attributes: ['literal' => $literal, 'wrapped' => ['__lc_escaped__' => $literal]]);
        self::assertSame(['api_key' => self::marker('SYNTH_S'), 'literal' => ['__lc_escaped__' => $literal],
            'wrapped' => ['__lc_escaped__' => ['__lc_escaped__' => $literal]]], $owner->toJson()['kwargs']);
        self::assertSame(0, $owner->filterCalls);
    }

    public function testStatelessCoreExplicitInputsNestedCopiesAndNoCallerWrites(): void
    {
        $nested = (object) ['token' => '', 'sibling' => 'SYNTH_SIBLING'];
        $bag = ['nested' => $nested, 'content' => 'SYNTH_CAPTURED'];
        $owner = (object) ['content' => 'SYNTH_UNREAD', 'secret' => 'SYNTH_UNREAD'];
        $record = Serializable::serializeSourceContract($owner, $bag, ['SYNTH_CORE', 'Explicit'], true,
            [['aliases' => [], 'secrets' => ['nested.token' => 'SYNTH_NESTED', 'absent.token' => 'SYNTH_MISSING'],
                'attributes' => ['extra' => 'SYNTH_ATTRIBUTE']]],
            ['content' => 'SYNTH_REFRESH'], ['nested' => (object) ['token' => 'SYNTH_CURRENT']]);
        self::assertSame('SYNTH_REFRESH', $record['kwargs']['content']);
        self::assertSame('SYNTH_ATTRIBUTE', $record['kwargs']['extra']);
        self::assertSame(self::marker('SYNTH_NESTED'), $record['kwargs']['nested']->token);
        self::assertSame('SYNTH_SIBLING', $record['kwargs']['nested']->sibling);
        self::assertArrayNotHasKey('absent', $record['kwargs']);
        self::assertSame('', $nested->token);
        self::assertSame($nested, $bag['nested']);
        self::assertSame('SYNTH_CAPTURED', $bag['content']);
        self::assertSame('SYNTH_UNREAD', $owner->content);
    }

    public static function undefinedSecretIntermediates(): iterable
    {
        yield 'B attribute undefined, absent instance' => ['B', 'none'];
        yield 'C public undefined' => ['C', 'undefined'];
        yield 'E captured undefined' => ['E', 'none'];
        yield 'A real instance object positive' => ['A', 'object'];
        yield 'A real instance array positive' => ['A', 'array'];
        yield 'F apiKey terminal control' => ['F', 'none'];
    }

    private static function readGuardInputs(string $label, string $shape): array
    {
        $u = Serializable::sourceContractUndefined();
        $bag = ['content' => 'SYNTH_C'];
        if ($label === 'E') {
            $bag['configuration'] = $u;
        }
        $attributes = in_array($label, ['A', 'B', 'F'], true) ? ['configuration' => $u] : [];
        $secrets = ['configuration.'.($label === 'F' ? 'apiKey' : 'name') => 'SYNTH_N'];
        $configuration = match ($shape) {
            'undefined' => $u,
            'object' => (object) ['name' => 'SYNTH_RAW'],
            'array' => ['name' => 'SYNTH_RAW'],
            default => null,
        };
        return [$bag, $attributes, $secrets, $configuration];
    }

    #[DataProvider('undefinedSecretIntermediates')]
    public function testUndefinedIntermediateReaderMatchesSourceThroughFourWrapperPaths(string $label, string $shape): void
    {
        [$bag, $attributes, $secrets, $configuration] = self::readGuardInputs($label, $shape);
        $owner = match ($label) {
            'A', 'B' => new S1CCfgAttr($bag, secrets: $secrets, attributes: $attributes),
            'F' => new S1CCfgKeyAttr($bag, secrets: $secrets, attributes: $attributes),
            default => new S1CCfg($bag, secrets: $secrets, attributes: $attributes),
        };
        if ($shape !== 'none') {
            $owner->configuration = $configuration;
        }
        $callerBefore = serialize($bag);
        $configurationBefore = serialize($configuration);
        $expected = self::readGuardRow($label)['jsonText'];
        foreach ([$owner->toSerializedConstructor(), $owner->toJson(), $owner->jsonSerialize(), $owner] as $value) {
            self::assertSame($expected, json_encode($value, JSON_THROW_ON_ERROR));
        }
        self::assertSame($bag, $owner->kwargs());
        self::assertSame($callerBefore, serialize($bag));
        self::assertSame($configurationBefore, serialize($configuration));
        if ($shape !== 'none') {
            self::assertSame($configuration, $owner->configuration);
        } else {
            self::assertFalse(property_exists($owner, 'configuration'));
        }
        self::assertSame(1, $owner->captureCalls);
        self::assertSame(0, $owner->magicCalls);
        self::assertSame('Value', Serializable::sourceContractUndefined()->name);
    }

    #[DataProvider('undefinedSecretIntermediates')]
    public function testUndefinedIntermediateReaderMatchesSourceThroughExplicitCore(string $label, string $shape): void
    {
        [$bag, $attributes, $secrets, $configuration] = self::readGuardInputs($label, $shape);
        $owner = new \stdClass();
        $secretState = $shape === 'none' ? [] : ['configuration' => $configuration];
        $before = serialize([$bag, $attributes, $secretState, $owner]);
        $record = Serializable::serializeSourceContract($owner, $bag, self::readGuardRow($label)['json']['id'], true,
            [['aliases' => [], 'secrets' => $secrets, 'attributes' => $attributes]], [], $secretState);
        self::assertSame(self::readGuardRow($label)['jsonText'], json_encode($record, JSON_THROW_ON_ERROR));
        self::assertSame($before, serialize([$bag, $attributes, $secretState, $owner]));
        if ($shape !== 'none') {
            self::assertSame($configuration, $secretState['configuration']);
        }
        self::assertSame([], Serializable::sourceContractPublicState($owner));
    }

    public static function missingConfigurations(): iterable
    {
        yield 'array' => ['array', ['apiKey' => 'SYNTH_RAW_KEY', 'baseURL' => 'SYNTH_URL']];
        yield 'object' => ['object', (object) ['apiKey' => 'SYNTH_RAW_KEY', 'baseURL' => 'SYNTH_URL']];
    }

    #[DataProvider('missingConfigurations')]
    public function testMissingSecretIntermediateMatchesFixedFullSourceWithoutSentinelFields(string $shape, mixed $configuration): void
    {
        $expected = self::repairRow('missing-configuration-'.$shape)['jsonText'];
        $original = json_encode($configuration, JSON_THROW_ON_ERROR);
        $owner = new S1CConfiguration(['content' => 'SYNTH_C'], secrets: ['configuration.apiKey' => 'SYNTH_CFG']);
        $owner->configuration = $configuration;
        foreach ([$owner->toSerializedConstructor(), $owner->toJson(), $owner->jsonSerialize(), $owner] as $value) {
            self::assertSame($expected, json_encode($value, JSON_THROW_ON_ERROR));
        }
        $bag = ['content' => 'SYNTH_C'];
        $record = Serializable::serializeSourceContract(new \stdClass(), $bag, ['SYNTH_CORE', 'Configuration'], true,
            [['aliases' => [], 'secrets' => ['configuration.apiKey' => 'SYNTH_CFG'], 'attributes' => []]], [], ['configuration' => $configuration]);
        self::assertSame($expected, json_encode($record, JSON_THROW_ON_ERROR));
        self::assertSame(['content' => 'SYNTH_C'], $bag);
        self::assertSame($bag, $owner->kwargs());
        self::assertSame($configuration, $owner->configuration);
        self::assertSame($original, json_encode($configuration, JSON_THROW_ON_ERROR));
        self::assertSame(['apiKey' => self::marker('SYNTH_CFG')], json_decode($expected, true)['kwargs']['configuration']);
        self::assertSame(1, $owner->captureCalls);
    }

    public function testRetainedSourcePartialDepthRedactionQuirkIsExplicitlyUnported(): void
    {
        $source = self::repairRow('unported-partial-depth-break');
        self::assertSame(['b' => self::marker('SYNTH_AB')], $source['json']['kwargs']);
        $bag = ['b' => 'SYNTH_RAW_B'];
        $record = Serializable::serializeSourceContract(new \stdClass(), $bag, ['SYNTH_CORE', 'Partial'], true,
            [['aliases' => [], 'secrets' => ['a.b' => 'SYNTH_AB'], 'attributes' => []]], [], []);
        // Source stops at the missing a then redacts root b; PHP guards the full path and leaves it raw.
        self::assertSame(['b' => 'SYNTH_RAW_B'], $record['kwargs']);
        self::assertNotSame($source['jsonText'], json_encode($record, JSON_THROW_ON_ERROR));
        self::assertSame(['b' => 'SYNTH_RAW_B'], $bag);
    }

    public function testRetainedUnicodeWhitespaceAndNumericWhitelistSeams(): void
    {
        $record = (new S1CFixture(["a\u{00a0}b" => 'SYNTH_SPACE']))->toJson();
        self::assertSame(['a_b' => 'SYNTH_SPACE'], self::repairRow('unported-js-unicode-whitespace')['json']['kwargs']);
        self::assertSame(["a\u{00a0}b" => 'SYNTH_SPACE'], $record['kwargs']);
        $bag = ['2' => 'SYNTH_NUMERIC'];
        self::assertSame([2 => 'SYNTH_NUMERIC'], self::repairRow('unported-int-coerced-whitelist')['captured']);
        self::assertSame([], Serializable::captureSourceContractBag($bag, ['2']));
        self::assertSame([2 => 'SYNTH_NUMERIC'], $bag);
    }

    public function testRetainedScalarIntermediateAdaptation(): void
    {
        self::assertSame('TypeError', self::repairRow('unported-scalar-path')['errorName']);
        $record = Serializable::serializeSourceContract(new \stdClass(), [], ['SYNTH_CORE', 'Scalar'], true,
            [['aliases' => [], 'secrets' => ['configuration.apiKey' => 'SYNTH_CFG'], 'attributes' => ['configuration' => 'SYNTH_SCALAR']]],
            [], ['configuration' => (object) ['apiKey' => 'SYNTH_RAW_KEY']]);
        self::assertSame(['configuration' => ['apiKey' => self::marker('SYNTH_CFG')]], $record['kwargs']);
    }

    public function testPrivateCaptureBagIsObservableInLegacyNativeObjectDumps(): void
    {
        $owner = new HumanMessage('SYNTH_CONTENT');
        $key = "\0".Serializable::class."\0sourceSerializationCapturedBag";
        self::assertArrayHasKey($key, (array) $owner);
        self::assertSame([], ((array) $owner)[$key]);
        self::assertStringContainsString('sourceSerializationCapturedBag', serialize($owner));
        self::assertEquals(new HumanMessage('SYNTH_CONTENT'), $owner);
        $before = json_encode($owner, JSON_THROW_ON_ERROR);
        serialize($owner);
        self::assertSame($before, json_encode($owner, JSON_THROW_ON_ERROR));
    }

    public function testNestedUndefinedFailsClosedAtJsonBoundary(): void
    {
        $owner = new S1CFixture(['nested' => ['value' => Serializable::sourceContractUndefined()]]);
        self::assertSame(Serializable::sourceContractUndefined(), $owner->toJson()['kwargs']['nested']['value']);
        $this->expectException(\JsonException::class);
        $this->expectExceptionMessage('Non-backed enums have no default serialization');
        json_encode($owner, JSON_THROW_ON_ERROR);
    }

    public function testLivePathSourceHazardAndNativeIdentitiesAreNeverWritten(): void
    {
        $live = new S1CLive();
        $date = new \DateTimeImmutable('2001-01-01T00:00:00Z');
        $owner = new S1CFixture(['live' => $live, 'date' => $date, 'native' => S1CNative::Token],
            secrets: ['live.token' => 'SYNTH_LIVE_SECRET', 'date.hidden' => 'SYNTH_ABSENT', 'native.name' => 'SYNTH_NATIVE_SECRET']);
        $record = $owner->toJson();
        self::assertSame(self::marker('SYNTH_LIVE_SECRET'), $record['kwargs']['live']->token);
        // Reproduced source hazard: shallow copy after escape leaves live sibling unescaped.
        self::assertSame(self::marker('SYNTH_LITERAL'), $record['kwargs']['live']->sibling);
        self::assertSame('SYNTH_LIVE_TOKEN', $live->token);
        self::assertSame(self::marker('SYNTH_LITERAL'), $live->sibling);
        self::assertSame($live, $owner->kwargs()['live']);
        self::assertSame($date, $record['kwargs']['date']);
        self::assertSame('Token', S1CNative::Token->name);
        self::assertSame('SYNTH_NATIVE', S1CNative::Token->value);
        // Retained PHP adaptation: the copied native enum becomes a public-field object on this path.
        self::assertInstanceOf(\stdClass::class, $record['kwargs']['native']);
        self::assertSame(self::marker('SYNTH_NATIVE_SECRET'), $record['kwargs']['native']->name);
        self::assertSame('SYNTH_NATIVE', $record['kwargs']['native']->value);
        self::assertSame(S1CNative::Token, $owner->kwargs()['native']);
    }

    public function testUserJsonSerializableEscapeSideEffectIsExplicitlyRetained(): void
    {
        $value = new class implements \JsonSerializable {
            public int $calls = 0;
            public function jsonSerialize(): mixed
            {
                ++$this->calls;
                return ['lc' => 1, 'type' => 'secret', 'id' => ['SYNTH_LITERAL']];
            }
        };
        $record = Serializable::serializeSourceContract(new \stdClass(), ['value' => $value], ['SYNTH_CORE', 'Plain'], true, [], [], []);
        self::assertSame(1, $value->calls);
        self::assertSame(['__lc_escaped__' => self::marker('SYNTH_LITERAL')], $record['kwargs']['value']);
    }

    public function testPublicLoadHumanProtocolSinkBareKwargsAndLiteralIsolation(): void
    {
        $literal = self::marker('SYNTH_LITERAL_SECRET');
        $owner = new S1CHuman(['content' => 'SYNTH_CONTENT', 'name' => 'SYNTH_CAPTURED_NAME',
            'additional_kwargs' => ['literal' => $literal]], secrets: ['name' => 'SYNTH_BRIDGE_SECRET']);
        $expected = ['lc' => 1, 'type' => 'constructor', 'id' => ['langchain_core', 'messages', 'HumanMessage'],
            'kwargs' => ['content' => 'SYNTH_CONTENT', 'name' => self::marker('SYNTH_BRIDGE_SECRET'),
                'additional_kwargs' => ['literal' => ['__lc_escaped__' => $literal]]]];
        self::assertSame($expected, $owner->toJson());
        $loaded = Load::load(json_encode($owner, JSON_THROW_ON_ERROR), ['secretsMap' => [
            'SYNTH_BRIDGE_SECRET' => 'SYNTH_LOAD_BRIDGE', 'SYNTH_LITERAL_SECRET' => 'SYNTH_NOT_SUBSTITUTED']]);
        self::assertInstanceOf(HumanMessage::class, $loaded);
        self::assertSame('SYNTH_LOAD_BRIDGE', $loaded->name);
        self::assertSame($literal, $loaded->additional_kwargs['literal']);
        self::assertSame('SYNTH_BARE', Load::load('{"name":{"lc":1,"type":"secret","id":["SYNTH_BARE_SECRET"]}}', ['secretsMap' => ['SYNTH_BARE_SECRET' => 'SYNTH_BARE']])['name']);
        try {
            Load::load(json_encode($owner, JSON_THROW_ON_ERROR));
            self::fail('Missing synthetic secret must fail.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Missing secret', $error->getMessage());
        }
        $this->expectException(\RuntimeException::class);
        Load::load('{"lc":1,"type":"constructor","id":["SYNTH_UNKNOWN","Unknown"],"kwargs":{}}');
    }

    public function testStaticMapsAreExcludedUnlessInstanceHookExplicitlyMapsThem(): void
    {
        self::assertSame(['display_name' => 'SYNTH_NAME'], (new S1CStaticAliases(['displayName' => 'SYNTH_NAME']))->toJson()['kwargs']);
        self::assertSame(['static_name' => 'SYNTH_NAME'], (new S1CExplicitStaticAliases(['displayName' => 'SYNTH_NAME']))->toJson()['kwargs']);
        self::assertSame([['aliases' => [], 'secrets' => [], 'attributes' => []]],
            Serializable::sourceContractHookLayers(new \stdClass(), ['aliases' => 'missingAlias', 'secrets' => 'missingSecret', 'attributes' => 'missingAttribute']));
        $owner = new class { public static function hook(): array { return []; } };
        $this->expectException(\InvalidArgumentException::class);
        Serializable::sourceContractHookLayers($owner, ['aliases' => 'hook', 'secrets' => 'hook', 'attributes' => 'hook']);
    }


    public function testFixedFullSourceParentWinnerProjectionThroughUnrelatedOwnerCore(): void
    {
        $row = json_decode(<<<'JSON'
{
  "label": "prototype-parent-wins",
  "fields": {
    "content": "SYNTH_CONTENT",
    "apiKey": "SYNTH_CAPTURED",
    "displayName": "SYNTH_DISPLAY",
    "nested": {
      "token": "SYNTH_NESTED",
      "nonSecret": {
        "lc": 1,
        "type": "secret",
        "id": [
          "SYNTH_LITERAL_SECRET"
        ]
      }
    },
    "drop": "SYNTH_DROP",
    "literal": {
      "lc": 1,
      "type": "secret",
      "id": [
        "SYNTH_LITERAL_SECRET"
      ]
    }
  },
  "json": {
    "lc": 1,
    "type": "constructor",
    "id": [
      "SYNTH_CORE",
      "Child"
    ],
    "kwargs": {
      "content": "SYNTH_CONTENT",
      "credential_wire": {
        "lc": 1,
        "type": "secret",
        "id": [
          "SYNTH_PARENT_SECRET"
        ]
      },
      "parent_name": "SYNTH_DISPLAY",
      "nested": {
        "token": {
          "lc": 1,
          "type": "secret",
          "id": [
            "SYNTH_NESTED_SECRET"
          ]
        },
        "nonSecret": {
          "__lc_escaped__": {
            "lc": 1,
            "type": "secret",
            "id": [
              "SYNTH_LITERAL_SECRET"
            ]
          }
        }
      },
      "literal": {
        "__lc_escaped__": {
          "lc": 1,
          "type": "secret",
          "id": [
            "SYNTH_LITERAL_SECRET"
          ]
        }
      },
      "injected": "SYNTH_PARENT_ATTRIBUTE"
    }
  },
  "native": {
    "lc": 1,
    "type": "constructor",
    "id": [
      "SYNTH_CORE",
      "Child"
    ],
    "kwargs": {
      "content": "SYNTH_CONTENT",
      "credential_wire": {
        "lc": 1,
        "type": "secret",
        "id": [
          "SYNTH_PARENT_SECRET"
        ]
      },
      "parent_name": "SYNTH_DISPLAY",
      "nested": {
        "token": {
          "lc": 1,
          "type": "secret",
          "id": [
            "SYNTH_NESTED_SECRET"
          ]
        },
        "nonSecret": {
          "__lc_escaped__": {
            "lc": 1,
            "type": "secret",
            "id": [
              "SYNTH_LITERAL_SECRET"
            ]
          }
        }
      },
      "literal": {
        "__lc_escaped__": {
          "lc": 1,
          "type": "secret",
          "id": [
            "SYNTH_LITERAL_SECRET"
          ]
        }
      },
      "injected": "SYNTH_PARENT_ATTRIBUTE"
    }
  },
  "captured": {
    "content": "SYNTH_CONTENT",
    "apiKey": "SYNTH_CAPTURED",
    "displayName": "SYNTH_DISPLAY",
    "nested": {
      "token": "SYNTH_NESTED",
      "nonSecret": {
        "lc": 1,
        "type": "secret",
        "id": [
          "SYNTH_LITERAL_SECRET"
        ]
      }
    },
    "drop": "SYNTH_DROP",
    "literal": {
      "lc": 1,
      "type": "secret",
      "id": [
        "SYNTH_LITERAL_SECRET"
      ]
    }
  }
}
JSON, true, 512, JSON_THROW_ON_ERROR);
        $bag = $row['fields'];
        $owner = new \stdClass();
        $record = Serializable::serializeSourceContract($owner, $bag, ['SYNTH_CORE', 'Child'], true, [
            ['aliases' => ['apiKey' => 'child_wire', 'displayName' => 'child_name'],
                'secrets' => ['apiKey' => 'SYNTH_CHILD_SECRET', 'nested.token' => 'SYNTH_NESTED_SECRET'],
                'attributes' => ['injected' => 'SYNTH_CHILD_ATTRIBUTE']],
            ['aliases' => ['apiKey' => 'credential_wire', 'displayName' => 'parent_name'],
                'secrets' => ['apiKey' => 'SYNTH_PARENT_SECRET', 'nested.token' => 'SYNTH_NESTED_SECRET'],
                'attributes' => ['injected' => 'SYNTH_PARENT_ATTRIBUTE', 'drop' => Serializable::sourceContractUndefined()]],
        ], [], []);
        self::assertSame($row['json'], $record);
        self::assertSame($row['captured'], $bag);
        self::assertSame([], Serializable::sourceContractPublicState($owner));
        self::assertSame(['lc' => 1, 'type' => 'not_implemented', 'id' => ['SYNTH_CORE', 'Child']],
            Serializable::serializeSourceContract($owner, $bag, ['SYNTH_CORE', 'Child'], false, [], [], []));
    }

    public function testInclusionRunsBeforeEscapeEvenWhenFinalMarkerHidesItsValue(): void
    {
        $value = new class implements \JsonSerializable {
            public int $calls = 0;
            public function jsonSerialize(): mixed { ++$this->calls; return 'SYNTH_CURRENT'; }
        };
        $owner = (object) ['apiKey' => $value];
        $record = Serializable::serializeSourceContract($owner, ['apiKey' => false], ['SYNTH_CORE', 'Plain'], true,
            [['aliases' => [], 'secrets' => ['apiKey' => 'SYNTH_S'], 'attributes' => []]], [], ['apiKey' => $value]);
        self::assertSame(['api_key' => self::marker('SYNTH_S')], $record['kwargs']);
        self::assertSame(1, $value->calls);
        self::assertSame($value, $owner->apiKey);
        $selfRecord = Serializable::serializeSourceContract($owner, ['self' => $owner], ['SYNTH_CORE', 'Plain'], true, [], [], []);
        self::assertSame(['lc' => 1, 'type' => 'not_implemented', 'id' => ['Object']], $selfRecord['kwargs']['self']);
    }

    public function testLegacyClassesRemainByteIdenticalAndUnactivated(): void
    {
        $literal = self::marker('SYNTH_LITERAL');
        $owner = new S1CLegacy(['apiKey' => 'SYNTH_CAPTURED', 'literal' => $literal]);
        self::assertSame('{"lc":1,"type":"constructor","id":["SYNTH_LEGACY","Fixture"],"kwargs":{"apiKey":"SYNTH_CAPTURED","literal":{"__lc_escaped__":{"lc":1,"type":"secret","id":["SYNTH_LITERAL"]}},"late":{"lc":1,"type":"secret","id":["SYNTH_LATE"]}}}', json_encode($owner, JSON_THROW_ON_ERROR));
        self::assertSame(0, $owner->hookCalls);
        $human = new HumanMessage('SYNTH_CONTENT');
        $before = json_encode($human, JSON_THROW_ON_ERROR);
        $human->content = 'SYNTH_LATER';
        self::assertSame($before, json_encode($human, JSON_THROW_ON_ERROR));
        $document = new Document('SYNTH_DOCUMENT');
        $before = json_encode($document, JSON_THROW_ON_ERROR);
        $document->pageContent = 'SYNTH_LATER';
        self::assertSame($before, json_encode($document, JSON_THROW_ON_ERROR));
    }
}

#[\AllowDynamicProperties]
class S1CFixture extends Serializable
{
    public mixed $content;
    public mixed $apiKey;
    public mixed $current;
    public mixed $discard;
    public int $captureCalls = 0;
    public int $eligibilityCalls = 0;
    public int $hookCalls = 0;
    public int $filterCalls = 0;
    public int $magicCalls = 0;
    protected string $hidden = 'SYNTH_PROTECTED';
    private string $privateSecret = 'SYNTH_PRIVATE';

    public function __construct(mixed $input = null, protected ?array $keys = null, protected bool $eligible = true,
        protected array $aliases = [], protected array $secrets = [], protected array $attributes = [])
    {
        $this->kwargs = is_array($input) ? $input : [];
        $this->captureSourceSerializationBag($input);
    }
    public static function lcId(): array { return ['SYNTH_CORE', 'Plain']; }
    protected function sourceSerializationEnabled(): bool { return true; }
    protected function sourceSerializationEligible(): bool { ++$this->eligibilityCalls; return $this->eligible; }
    protected function sourceSerializationCaptureKeys(): ?array { ++$this->captureCalls; return $this->keys; }
    protected function sourceSerializationAliases(): array { ++$this->hookCalls; return $this->aliases; }
    protected function sourceSerializationSecrets(): array { ++$this->hookCalls; return $this->secrets; }
    protected function sourceSerializationAttributes(): array { ++$this->hookCalls; return $this->attributes; }
    protected function filterKwargs(array $kwargs): array { ++$this->filterCalls; return $kwargs; }
    public function __get(string $name): mixed { ++$this->magicCalls; return 'SYNTH_MAGIC'; }
}
class S1CCfg extends S1CFixture
{
    public static function lcId(): array { return ['SYNTH_CORE', 'Cfg']; }
}
class S1CCfgAttr extends S1CCfg
{
    public static function lcId(): array { return ['SYNTH_CORE', 'CfgAttr']; }
}
class S1CCfgKeyAttr extends S1CFixture
{
    public static function lcId(): array { return ['SYNTH_CORE', 'CfgKeyAttr']; }
}
class S1CConfiguration extends S1CFixture
{
    public static function lcId(): array { return ['SYNTH_CORE', 'Configuration']; }
}
class S1CKeysFixture extends S1CFixture
{
    public static function lcId(): array { return ['SYNTH_CORE', 'KeysReadd']; }
}
class S1CParent extends S1CFixture
{
    protected function sourceSerializationAliases(): array { return ['apiKey' => 'parent_wire']; }
    protected function sourceSerializationSecrets(): array { return ['apiKey' => 'SYNTH_PARENT']; }
    protected function sourceSerializationAttributes(): array { return ['derived' => $this->current, 'origin' => 'SYNTH_PARENT']; }
}
class S1CChild extends S1CParent
{
    protected function sourceSerializationAliases(): array { return ['apiKey' => 'child_wire']; }
    protected function sourceSerializationSecrets(): array { return ['apiKey' => 'SYNTH_CHILD']; }
    protected function sourceSerializationAttributes(): array { return ['derived' => $this->current, 'origin' => 'SYNTH_CHILD']; }
}
class S1CInheritedGrandchild extends S1CChild
{
    public static function lcId(): array { return ['SYNTH_CORE', 'InheritedGrandchild']; }
}
class S1CTimingParent extends S1CFixture
{
    public array $events = [];
    protected function sourceSerializationAliases(): array { $this->events[] = 'P.alias:'.$this->current; $this->current = 'SYNTH_PARENT_AFTER_HOOK'; return []; }
    protected function sourceSerializationSecrets(): array { $this->events[] = 'P.secret:'.$this->current; return []; }
    protected function sourceSerializationAttributes(): array { $this->events[] = 'P.attribute:'.$this->current; return ['derived' => $this->current]; }
}
class S1CTimingChild extends S1CTimingParent
{
    protected function sourceSerializationAliases(): array { $this->events[] = 'C.alias:'.$this->current; $this->current = 'SYNTH_CHILD_AFTER_HOOK'; return []; }
    protected function sourceSerializationSecrets(): array { $this->events[] = 'C.secret:'.$this->current; return []; }
    protected function sourceSerializationAttributes(): array { $this->events[] = 'C.attribute:'.$this->current; return ['derived' => $this->current]; }
}
class S1CTimingGrandchild extends S1CTimingChild
{
    public static function lcId(): array { return ['SYNTH_CORE', 'TimingGrandchild']; }
}
class S1CStaticAliases extends S1CFixture
{
    public static function lcAliases(): array { return ['displayName' => 'static_name']; }
}
class S1CExplicitStaticAliases extends S1CStaticAliases
{
    protected function sourceSerializationAliases(): array { return static::lcAliases(); }
}
class S1CDefaultEligibility extends Serializable
{
    public static function lcId(): array { return ['SYNTH_CORE', 'SourceDefault']; }
    protected function sourceSerializationEnabled(): bool { return true; }
}
class S1CNeverCaptured extends S1CDefaultEligibility
{
    public static function lcId(): array { return ['SYNTH_CORE', 'Plain']; }
    public function __construct() { $this->kwargs = ['content' => 'SYNTH_LEGACY_CAPTURE']; }
    protected function sourceSerializationEligible(): bool { return true; }
}
class S1CHuman extends S1CFixture
{
    public static function lcId(): array { return ['langchain_core', 'messages', 'HumanMessage']; }
}
class S1CLive extends Serializable
{
    public string $token = 'SYNTH_LIVE_TOKEN';
    public array $sibling = ['lc' => 1, 'type' => 'secret', 'id' => ['SYNTH_LITERAL']];
    public static function lcId(): array { return ['SYNTH_CORE', 'Live']; }
}
enum S1CNative: string { case Token = 'SYNTH_NATIVE'; }
class S1CLegacy extends Serializable
{
    public int $hookCalls = 0;
    public function __construct(array $bag) { $this->kwargs = $bag; }
    public static function lcId(): array { return ['SYNTH_LEGACY', 'Fixture']; }
    public static function lcSerializable(): bool { return false; }
    protected function sourceSerializationSecrets(): array { ++$this->hookCalls; return ['apiKey' => 'SYNTH_FORBIDDEN']; }
    protected function filterKwargs(array $kwargs): array { $kwargs['late'] = ['lc' => 1, 'type' => 'secret', 'id' => ['SYNTH_LATE']]; return $kwargs; }
}
