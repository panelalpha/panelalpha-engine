<?php

namespace App\Http\Requests;

use App\Lib\Project\Create\CreateBody;
use App\Lib\Project\Create\CreateFields;
use App\Lib\Project\Create\CreateShape;
use App\Lib\Project\Create\ProjectSpec;
use App\Lib\Project\NewProjectRules;
use Illuminate\Contracts\Validation\Validator;

/**
 * The flat body is validated as POST /users validates it; the typed body's shape by {@see CreateBody}, its
 * values by the same flat rules, and each refusal named by the field the caller sent.
 */
class ProjectCreateRequest extends UserStoreRequest
{
    /** The typed body; null when the caller sent the flat one. */
    private ?CreateBody $body = null;

    protected function prepareForValidation(): void
    {
        if (! CreateShape::isTyped($this->all())) {
            parent::prepareForValidation();

            return;
        }

        $this->body = CreateBody::forCreate($this->all());
        $this->body->assertWellFormed();
        $flat = $this->body->flat();
        [$changes] = NewProjectRules::normalised($flat);
        $this->replace(array_replace($flat, $changes));
    }

    public function rules(): array
    {
        return $this->body === null
            ? parent::rules()
            : NewProjectRules::rules('name', $this->input('domain'), NewProjectRules::createsContainer($this->all()), $this->body->source()->deployKey() !== null);
    }

    public function nameField(): string
    {
        return $this->body === null ? parent::nameField() : 'name';
    }

    public function attributes(): array
    {
        return $this->body === null
            ? parent::attributes()
            : ['username' => 'name', 'domain' => 'domain.name'] + CreateShape::LEGACY_FIELDS;
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->body === null) {
            parent::failedValidation($validator);

            return;
        }

        CreateFields::answering(fn () => parent::failedValidation($validator));
    }

    public function spec(): ProjectSpec
    {
        $input = $this->newProjectInput($this->nameField(), $this->body?->source()->deployKey());

        return $this->body === null ? ProjectSpec::flat($input) : new ProjectSpec($input, $this->body->source());
    }
}
