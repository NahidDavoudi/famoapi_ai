<?php

namespace App\Core;

class Validator
{
    private array $errors = [];

    public function addError(string $message): self
    {
        $this->errors[] = $message;
        return $this;
    }

    public function requireBody(mixed $body, string $message = 'بدنه درخواست الزامی است و باید یک شیء معتبر باشد'): self
    {
        if (!is_array($body) || $body === [] || array_is_list($body)) {
            $this->errors[] = $message;
        }
        return $this;
    }

    public function required(string $field, string $label, mixed $value): self
    {
        if ($value === null || $value === false || $value === []) {
            $this->errors[] = "{$label} الزامی است";
        } elseif (is_string($value) && trim($value) === '') {
            $this->errors[] = "{$label} الزامی است";
        }
        return $this;
    }

    public function string(string $field, string $label, mixed $value): self
    {
        if ($value !== null && !is_string($value) && !is_numeric($value)) {
            $this->errors[] = "{$label} باید رشته متنی باشد";
        }
        return $this;
    }

    public function numeric(string $field, string $label, mixed $value): self
    {
        if ($value !== null && $value !== '' && (!is_scalar($value) || is_bool($value) || !is_numeric($value))) {
            $this->errors[] = "{$label} باید عددی باشد";
        }
        return $this;
    }

    public function integer(string $field, string $label, mixed $value, ?int $min = null, ?int $max = null): self
    {
        if ($value === null || $value === '') {
            return $this;
        }
        if (!is_scalar($value) || is_bool($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            $this->errors[] = "{$label} باید عدد صحیح باشد";
            return $this;
        }
        $intVal = (int) $value;
        if ($min !== null && $intVal < $min) {
            $this->errors[] = "{$label} باید حداقل {$min} باشد";
        }
        if ($max !== null && $intVal > $max) {
            $this->errors[] = "{$label} نمی‌تواند بیشتر از {$max} باشد";
        }
        return $this;
    }

    public function positiveInt(string $field, string $label, mixed $value): self
    {
        if ($value === null || $value === '') {
            return $this;
        }
        if (!is_scalar($value) || is_bool($value) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            $this->errors[] = "{$label} باید یک عدد صحیح مثبت باشد";
        }
        return $this;
    }

    public function boolean(string $field, string $label, mixed $value): self
    {
        if ($value === null || $value === '') {
            return $this;
        }
        if (!in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true)) {
            $this->errors[] = "{$label} باید مقدار بولی معتبر باشد";
        }
        return $this;
    }

    public function phone(string $field, string $label, mixed $value): self
    {
        if ($value !== null && $value !== '') {
            if (!is_scalar($value) || is_bool($value) || !preg_match('/^09\d{9}$/', (string) $value)) {
                $this->errors[] = "{$label} باید با 09 شروع شود و 11 رقم باشد";
            }
        }
        return $this;
    }

    public function nationalId(string $field, string $label, mixed $value): self
    {
        if ($value !== null && $value !== '') {
            if (!is_scalar($value) || is_bool($value) || !preg_match('/^\d{10}$/', (string) $value)) {
                $this->errors[] = "{$label} باید 10 رقم باشد";
            }
        }
        return $this;
    }

    public function maxLength(string $field, string $label, mixed $value, int $max): self
    {
        if ($value !== null && $value !== '') {
            if (!is_scalar($value) || is_bool($value)) {
                $this->errors[] = "{$label} باید رشته متنی باشد";
            } elseif (mb_strlen((string) $value) > $max) {
                $this->errors[] = "{$label} نمی‌تواند بیشتر از {$max} کاراکتر باشد";
            }
        }
        return $this;
    }

    public function minLength(string $field, string $label, mixed $value, int $min): self
    {
        if ($value !== null && $value !== '') {
            if (!is_scalar($value) || is_bool($value)) {
                $this->errors[] = "{$label} باید رشته متنی باشد";
            } elseif (mb_strlen((string) $value) < $min) {
                $this->errors[] = "{$label} باید حداقل {$min} کاراکتر باشد";
            }
        }
        return $this;
    }

    public function inArray(string $field, string $label, mixed $value, array $allowed): self
    {
        if ($value !== null && $value !== '' && !in_array($value, $allowed)) {
            $this->errors[] = "{$label} نامعتبر است";
        }
        return $this;
    }

    public function date(string $field, string $label, mixed $value): self
    {
        if ($value !== null && $value !== '') {
            if (!is_string($value)) {
                $this->errors[] = "{$label} نامعتبر است";
                return $this;
            }
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
                $this->errors[] = "فرمت {$label} باید به صورت YYYY-MM-DD باشد";
            }
        }
        return $this;
    }

    public function time(string $field, string $label, mixed $value): self
    {
        if ($value !== null && $value !== '') {
            if (!is_string($value) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', trim($value))) {
                $this->errors[] = "فرمت {$label} باید به صورت HH:MM باشد";
            }
        }
        return $this;
    }

    public function arrayList(string $field, string $label, mixed $value, bool $nonEmpty = false): self
    {
        if ($value === null) {
            return $this;
        }
        if (!is_array($value)) {
            $this->errors[] = "{$label} باید یک آرایه معتبر باشد";
        } elseif ($nonEmpty && $value === []) {
            $this->errors[] = "{$label} نمی‌تواند خالی باشد";
        }
        return $this;
    }

    public function email(string $field, string $label, mixed $value): self
    {
        if ($value !== null && $value !== '') {
            if (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                $this->errors[] = "{$label} معتبر نیست";
            }
        }
        return $this;
    }

    public function regex(string $field, string $label, mixed $value, string $pattern, ?string $customMessage = null): self
    {
        if ($value !== null && $value !== '') {
            if (!is_scalar($value) || is_bool($value) || !preg_match($pattern, (string) $value)) {
                $this->errors[] = $customMessage ?? "فرمت {$label} نامعتبر است";
            }
        }
        return $this;
    }

    public function passes(): bool
    {
        return empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): string
    {
        return $this->errors[0] ?? '';
    }

    public function validateOrThrow(int $status = 422, string $code = 'VALIDATION_ERROR'): void
    {
        if (!$this->passes()) {
            throw new ApiException($this->firstError(), $status, $code);
        }
    }
}
