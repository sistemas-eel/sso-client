<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adiciona o campo codpes à tabela users e torna password opcional,
     * pois o login é realizado via Portal SSO.
     *
     * É seguro rodar mesmo que o pacote uspdev/senhaunica-socialite
     * também esteja instalado: as verificações com hasColumn() garantem
     * que nenhuma coluna será criada em duplicata.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Adiciona codpes apenas se ainda não existir
            // (pode já ter sido criada pelo senhaunica-socialite)
            if (!Schema::hasColumn('users', 'codpes')) {
                $table->integer('codpes')->nullable()->after('id');
            }

            // Torna password opcional: autenticação é delegada ao SSO
            // (espelha o comportamento do senhaunica-socialite)
            if (Schema::hasColumn('users', 'password')) {
                $table->string('password')->nullable()->change();
            }
        });
    }

    /**
     * Reverte as alterações feitas por esta migration.
     *
     * Atenção: o down() só remove codpes se a coluna existir.
     * Se o senhaunica-socialite também estiver instalado, a remoção
     * pode entrar em conflito com o rollback dele — nesse caso,
     * gerencie a ordem de rollback manualmente.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'codpes')) {
                $table->dropColumn('codpes');
            }

            if (Schema::hasColumn('users', 'password')) {
                $table->string('password')->nullable(false)->change();
            }
        });
    }
};
