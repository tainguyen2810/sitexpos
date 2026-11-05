<?php

use SiteXPost\Modules\AiGateway\Gateway\JsonSchemaValidator;
use SiteXPost\Modules\AiGateway\Workflows\AiTestWorkflow;

it('accepts output matching the schema', function () {
    $value = json_decode('{"summary":"ok","keywords":["a","b"],"sentiment":"neutral"}', true);
    expect((new JsonSchemaValidator)->validate($value, AiTestWorkflow::SCHEMA))->toBe([]);
});

it('rejects missing, extra, wrongly typed and out-of-enum fields', function () {
    $v = new JsonSchemaValidator;
    $schema = AiTestWorkflow::SCHEMA;

    expect($v->validate(json_decode('{"summary":"ok","keywords":["a"]}', true), $schema))->not->toBe([])
        ->and($v->validate(json_decode('{"summary":"ok","keywords":["a"],"sentiment":"neutral","extra":1}', true), $schema))->not->toBe([])
        ->and($v->validate(json_decode('{"summary":1,"keywords":["a"],"sentiment":"neutral"}', true), $schema))->not->toBe([])
        ->and($v->validate(json_decode('{"summary":"x","keywords":[1],"sentiment":"neutral"}', true), $schema))->not->toBe([])
        ->and($v->validate(json_decode('{"summary":"x","keywords":[],"sentiment":"angry"}', true), $schema))->not->toBe([])
        ->and($v->validate('not an object', $schema))->not->toBe([]);
});
