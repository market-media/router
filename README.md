# Router @market-media

Biblioteca de roteamento PHP com rotas nomeadas, controladores, callbacks, grupos e middlewares.

Requer **PHP 8.5 ou superior**. Não possui dependências de execução externas.

## Instalação

```sh
composer require market-media/router
```

Para trabalhar neste repositório:

```sh
composer install
composer test
composer test:http
```

Os comandos devem usar PHP 8.5+. O segundo teste inicia um servidor PHP temporário em uma porta local disponível e verifica requisições HTTP reais.

## Uso

Configure uma URL base fixa, sem credenciais, query string ou fragmento. Não a derive diretamente do cabeçalho `Host` recebido.

```php
<?php

use MarketMedia\Router\Router;

require __DIR__ . '/vendor/autoload.php';

$router = new Router('https://example.com');
$router->namespace('App\Controller');

$router->get('/', 'Home:index', 'home');
$router->get('/users/{id}', 'User:show', 'users.show');
$router->post('/users', 'User:create', middleware: App\Middleware\Authenticated::class);
$router->put('/users/{id}', 'User:update', middleware: App\Middleware\Authenticated::class);
$router->patch('/users/{id}', 'User:update', middleware: App\Middleware\Authenticated::class);
$router->delete('/users/{id}', 'User:delete', middleware: App\Middleware\Authenticated::class);

if (!$router->dispatch()) {
    http_response_code($router->error() ?? 500);
    echo 'Não foi possível processar a requisição.';
}
```

As ações públicas recebem um array. O controlador recebe o roteador no construtor. Callbacks recebem os dados e o roteador:

```php
$router->get('/hello/{name}', function (array $data, Router $router): void {
    echo htmlspecialchars($data['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
});
```

O caminho é recebido no parâmetro interno `route`. A raiz e barras finais são normalizadas. Exemplos de configuração:

Apache:

```apacheconfig
Options -Indexes
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php?route=/$1 [L,QSA]
```

Nginx, dentro do servidor já configurado para executar PHP:

```nginx
location / {
    try_files $uri $uri/ /index.php?route=$uri&$args;
}
```

`route` é reservado ao roteador. Não use esse parâmetro em formulários ou links como dado da aplicação. Configure o servidor e o proxy para aplicar políticas de acesso ao mesmo caminho efetivamente despachado pela aplicação.

## Middlewares e grupos

```php
$router->group('admin', App\Middleware\Authenticated::class);
$router->get('/users/{id}', 'User:show', middleware: App\Middleware\Administrator::class);
$router->group(null); // encerra o grupo atual
```

O middleware do grupo executa antes dos middlewares específicos. Informar uma lista vazia na rota não remove a proteção do grupo. `group()` seleciona um grupo; não cria uma pilha de grupos aninhados.

```php
final class Owner
{
    public function handle(Router $router): bool
    {
        $id = $router->data()['id'] ?? null;
        // Consulte a identidade autenticada no servidor e autorize este recurso.
        return is_string($id) && $this->authorized($id);
    }

    private function authorized(string $id): bool
    {
        // Substitua por sua política de autorização. O padrão é negar.
        return false;
    }
}
```

`handle()` deve retornar exatamente `true` para permitir a execução. Recusas produzem erro `403`. Uma classe inexistente ou uma ação inacessível não libera o controlador.

Middleware e controlador recebem os mesmos parâmetros da rota selecionada. Valores do caminho têm precedência sobre campos homônimos do corpo.

## Rotas nomeadas e redirecionamentos

```php
$url = $router->route('users.show', ['id' => 42, 'tab' => 'profile']);
// https://example.com/users/42?tab=profile

$router->redirect('users.show', ['id' => 42]);
$router->redirect('/login');
```

Cada placeholder é codificado com `rawurlencode()`. Parâmetros obrigatórios ausentes, arrays, segmentos vazios, `.` e `..` geram `InvalidArgumentException`. Parâmetros adicionais formam uma query string RFC 3986. Ao inserir uma URL em HTML, aplique também `htmlspecialchars()` no atributo.

Redirecionamentos aceitam somente a origem configurada: mesmo esquema, host e porta efetiva. URLs externas, credenciais, caracteres de controle, barras invertidas e destinos relativos a protocolo (`//host`) são rejeitados com `InvalidArgumentException`. Destinos internos preservam o caminho da URL base. `redirect()` envia `302` e encerra a execução; chame-o antes de produzir saída.

## Dados e segurança

- Parâmetros são extraídos das capturas da rota selecionada, preservando ocorrências do nome do grupo dentro dos valores.
- Trechos estáticos das rotas são literais, inclusive pontos e outros caracteres de expressão regular.
- Rotas estáticas têm precedência sobre dinâmicas. Entre dinâmicas, vence a de maior comprimento literal, seguida pela de menos parâmetros; empates preservam a ordem de cadastro. Evite padrões ambíguos com políticas de autorização diferentes.
- `data()` retorna os dados da rota selecionada, ou `null` antes do despacho/sem correspondência.
- `current()` retorna somente `route`, `name`, `method`, `namespace` e `group`, ou `null` sem rota selecionada. `__debugInfo()` não expõe dados da requisição.
- Entradas continuam sendo dados não confiáveis. Valide tipos, formatos, limites e autorização na aplicação. Use parâmetros preparados em SQL e escape apropriado ao contexto de saída.
- O caminho aceita no máximo 4096 bytes. Entradas estruturais inválidas produzem `400`.
- O limite de corpo declarado é 1 MiB. PUT/PATCH/DELETE também têm a leitura real limitada, inclusive sem `Content-Length`. Configure limites equivalentes no servidor e em `post_max_size`/`upload_max_filesize`, especialmente para POST multipart e uploads.
- POST utiliza `$_POST`. PUT/PATCH/DELETE aceitam corpos `application/x-www-form-urlencoded`, lidos uma vez. Corpos não vazios em outros formatos, incluindo JSON, produzem `415`.
- `_method` é aceito somente em POST real, com `POST`, `PUT`, `PATCH` ou `DELETE` exatos. O campo é removido antes de entregar dados ao controlador.
- O roteador não inicia sessões nem implementa autenticação ou CSRF globalmente. A aplicação deve aplicar esses controles a todas as operações autenticadas por cookies, incluindo POST com `_method`.
- `error()` informa `400`, `403`, `404`, `405`, `413`, `415` ou `501`; a aplicação deve definir o status HTTP. Não exiba exceções, dumps ou segredos em produção.

## Exemplos

`exemple/controller` demonstra callbacks, controladores, rotas nomeadas e middlewares. As rotas protegidas exigem uma sessão autenticada: `user_id` deve ser definido pelo servidor após autenticação; administradores também exigem `role === 'admin'`. Não existe login por parâmetro de URL.

`exemple/spoofing` demonstra POST com `_method`. Os formulários usam um token CSRF vinculado à sessão, validado também após a troca de método. Os exemplos não imprimem dados enviados pelo usuário. Ajuste a constante `BASE` ao seu ambiente. Estes exemplos não fornecem um sistema completo de login.

## Migração para PHP 8.5

Esta atualização contém mudanças de comportamento que devem ser revisadas antes da publicação:

1. Atualize PHP CLI, FPM/Apache, contêineres e CI para 8.5+, execute `composer install` e configure a IDE para PHP 8.5.
2. Revise middlewares específicos: agora são adicionados à proteção do grupo, em vez de substituí-la. Cada middleware deve retornar `bool` e permitir apenas com `true`.
3. Substitua consultas a `current()->data` por `data()`. Metadados internos e corpos não fazem mais parte de `current()` nem dos dumps do roteador.
4. Revise rotas sobrepostas: rotas estáticas ganham prioridade e empates preservam o primeiro cadastro, em vez da última correspondência.
5. Substitua redirecionamentos externos por um fluxo explicitamente controlado pela aplicação. A biblioteca permite apenas a origem da URL base.
6. Forneça todos os parâmetros obrigatórios ao gerar URLs nomeadas. Adapte clientes PUT/PATCH/DELETE ao formato de formulário suportado ou implemente uma camada de parsing específica antes de integrar JSON.
7. Trate os novos erros `403`, `413` e `415` e proteja operações autenticadas por cookies com CSRF.

Consulte também o [guia oficial de migração do PHP 8.5](https://www.php.net/manual/en/migration85.php).

## Licença

MIT. Consulte [LICENSE](LICENSE).
