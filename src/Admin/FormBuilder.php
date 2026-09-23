<?php

declare(strict_types=1);

namespace Guild\Framework\Admin;

use Guild\Rivet\Component\Button;
use Guild\Rivet\Component\Component;
use Guild\Rivet\Component\Form\Checkbox;
use Guild\Rivet\Component\Form\FieldGroup;
use Guild\Rivet\Component\Form\FormField;
use Guild\Rivet\Component\Form\Radio;
use Guild\Rivet\Component\Form\TextInput;
use Guild\Rivet\Enum\ButtonPurpose;
use Guild\Rivet\Enum\ButtonType;
use Guild\Rivet\Html\Html;
use Guild\Rivet\Render\RenderContext;

/**
 * Builds one administration form from Rivet components.
 *
 * One builder per form: its render context hands out the element ids, so
 * every label and description in the form points at the right control.
 * Every value is escaped by the components.
 *
 * @internal
 */
final class FormBuilder
{
    private readonly RenderContext $context;

    /**
     * @var list<string>
     */
    private array $parts = [];

    public function __construct(private readonly string $action, private readonly Csrf $csrf)
    {
        $this->context = new RenderContext();
    }

    /**
     * @param  list<string>  $errors
     */
    public function text(string $name, string $label, string $value = '', array $errors = [], ?string $helperText = null): self
    {
        $field = new FormField(label: $label, required: true, helperText: $helperText, errors: $errors);

        $this->parts[] = $this->around($field, fn (): string => new TextInput(name: $name, value: $value)->render($this->context));

        return $this;
    }

    public function checkbox(string $name, string $label, bool $checked, ?string $description = null): self
    {
        $this->parts[] = new Checkbox(name: $name, value: '1', label: $label, checked: $checked, description: $description)
            ->render($this->context);

        return $this;
    }

    /**
     * @param  list<array{value: string, label: string, checked: bool, description?: string}>  $options
     * @param  list<string>  $errors
     */
    public function checkboxes(string $name, string $legend, array $options, ?string $helperText = null, array $errors = []): self
    {
        return $this->choices($name . '[]', $legend, $options, $helperText, $errors, radio: false);
    }

    /**
     * @param  list<array{value: string, label: string, checked: bool, description?: string}>  $options
     * @param  list<string>  $errors
     */
    public function radios(string $name, string $legend, array $options, ?string $helperText = null, array $errors = []): self
    {
        return $this->choices($name, $legend, $options, $helperText, $errors, radio: true);
    }

    public function hidden(string $name, string $value): self
    {
        $this->parts[] = Html::el('input')->attr('type', 'hidden')->attr('name', $name)->attr('value', $value)->render();

        return $this;
    }

    /**
     * Markup placed between fields, already escaped by whoever built it.
     */
    public function html(string $html): self
    {
        $this->parts[] = $html;

        return $this;
    }

    public function render(string $submit, ButtonPurpose $purpose = ButtonPurpose::Default, ?string $cancelHref = null): string
    {
        $actions = Html::el('div')->class('rvt-button-group', 'rvt-m-top-lg')->html(
            new Button(text: $submit, purpose: $purpose, type: ButtonType::Submit)->render($this->context),
        );

        if ($cancelHref !== null) {
            $actions->children(Html::el('a')->class('rvt-button', 'rvt-button--secondary')->attr('href', $cancelHref)->text('Cancel'));
        }

        return Html::el('form')
            ->attr('method', 'post')
            ->attr('action', $this->action)
            ->children($this->csrf->field())
            ->html(implode('', array_map(
                static fn (string $part): string => Html::el('div')->class('rvt-m-bottom-md')->html($part)->render(),
                $this->parts,
            )))
            ->children($actions)
            ->render();
    }

    /**
     * @param  list<array{value: string, label: string, checked: bool, description?: string}>  $options
     * @param  list<string>  $errors
     */
    private function choices(string $name, string $legend, array $options, ?string $helperText, array $errors, bool $radio): self
    {
        $group = new FieldGroup(legend: $legend, helperText: $helperText, errors: $errors);

        $this->parts[] = $this->around($group, function () use ($name, $options, $radio): string {
            $html = '';

            foreach ($options as $option) {
                $choice = $radio
                    ? new Radio(name: $name, value: $option['value'], label: $option['label'], checked: $option['checked'], description: $option['description'] ?? null)
                    : new Checkbox(name: $name, value: $option['value'], label: $option['label'], checked: $option['checked'], description: $option['description'] ?? null);

                $html .= $choice->render($this->context);
            }

            return $html;
        });

        return $this;
    }

    /**
     * Render $outer around what $inner renders while $outer is open, which is
     * how a control finds the field or group it belongs to.
     *
     * @param  callable(): string  $inner
     */
    private function around(Component $outer, callable $inner): string
    {
        $this->context->open($outer);

        try {
            $content = $inner();
        } finally {
            $this->context->close();
        }

        return $outer->render($this->context, $content);
    }
}
