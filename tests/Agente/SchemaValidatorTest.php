<?php

namespace PortalSistemas\SSOClient\Tests\Agente;

use PortalSistemas\SSOClient\Agente\AgenteValidationException;
use PortalSistemas\SSOClient\Agente\SchemaValidator;
use PHPUnit\Framework\TestCase;

class SchemaValidatorTest extends TestCase
{
    public function test_fails_when_required_field_is_missing(): void
    {
        $validator = new SchemaValidator([
            [
                'chave' => 'patrimonio',
                'tipo' => 'string',
                'obrigatorio' => true,
            ],
        ]);

        try {
            $validator->validate([]);
            $this->fail('Era esperado erro de validação.');
        } catch (AgenteValidationException $e) {
            $this->assertSame('campo_obrigatorio', $e->errors()[0]['codigo']);
            $this->assertSame('patrimonio', $e->errors()[0]['campo']);
        }
    }

    public function test_fails_when_email_is_invalid(): void
    {
        $validator = new SchemaValidator([
            [
                'chave' => 'email_contato',
                'tipo' => 'email',
                'obrigatorio' => true,
            ],
        ]);

        $this->expectException(AgenteValidationException::class);

        $validator->validate([
            'email_contato' => 'email-invalido',
        ]);
    }

    public function test_fails_when_enum_value_is_invalid(): void
    {
        $validator = new SchemaValidator([
            [
                'chave' => 'urgencia',
                'tipo' => 'enum',
                'opcoes' => ['baixa', 'media', 'alta'],
            ],
        ]);

        try {
            $validator->validate([
                'urgencia' => 'critica',
            ]);
            $this->fail('Era esperado erro de enum invalido.');
        } catch (AgenteValidationException $e) {
            $this->assertSame('enum_invalido', $e->errors()[0]['codigo']);
        }
    }

    public function test_optional_fields_can_be_absent(): void
    {
        $validator = new SchemaValidator([
            [
                'chave' => 'urgencia',
                'tipo' => 'enum',
                'opcoes' => ['baixa', 'media', 'alta'],
                'obrigatorio' => false,
            ],
        ]);

        $validator->validate([]);

        $this->assertSame([], $validator->errors());
    }

    public function test_valid_payload_passes(): void
    {
        $validator = new SchemaValidator([
            [
                'chave' => 'patrimonio',
                'tipo' => 'string',
                'obrigatorio' => true,
            ],
            [
                'chave' => 'urgencia',
                'tipo' => 'enum',
                'opcoes' => ['baixa', 'media', 'alta'],
            ],
            [
                'chave' => 'email_contato',
                'tipo' => 'email',
            ],
        ]);

        $validator->validate([
            'patrimonio' => 'USP-12345',
            'urgencia' => 'media',
            'email_contato' => 'teste@usp.br',
        ]);

        $this->assertSame([], $validator->errors());
    }
}
