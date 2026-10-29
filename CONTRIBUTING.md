# Contribuindo com o OpenMenuExpress

Obrigado pelo interesse! Algumas regras para manter o projeto simples e consistente.

## Fluxo

1. Abra uma issue descrevendo o problema ou a proposta antes de um pull request grande.
2. Crie um branch a partir de `main` (`feat/checkout-cupom`, `fix/frete-km`).
3. Faça commits pequenos, com mensagens no formato [Conventional Commits](https://www.conventionalcommits.org/pt-br/) (`feat:`, `fix:`, `refactor:`, `test:`, `docs:`).
4. Assine cada commit com `git commit -s` (veja "Origem das contribuições").
5. Antes de abrir o pull request, rode `composer test` e `npm run check`.

## Origem das contribuições (DCO)

O projeto usa o [Developer Certificate of Origin](https://developercertificate.org/), sem acordo de cessão de direitos (CLA). Ao assinar um commit com `git commit -s`, você adiciona a linha `Signed-off-by: Seu Nome <seu@email>` e declara que tem o direito de enviar aquele código sob a licença do projeto.

Toda contribuição entra sob a mesma licença do projeto, a [AGPL-3.0](LICENSE). Não há licença dupla: as regras valem igualmente para todos.

## Regras do código

- O sistema nunca guarda dinheiro nem chaves privadas de lojistas. Todo pagamento cai direto na conta ou carteira de quem vendeu; o servidor só cria cobranças, observa e registra.
- Toda regra de negócio que envolve dinheiro (preço, desconto, frete, total) é calculada no servidor e tem teste.
- Valores em dinheiro são inteiros em centavos, em colunas `*_cents`.
- Toda tabela de dados de uma loja tem `store_id`.
- Integrações externas entram por um contrato (`PaymentGateway`, `MessagingProvider`, `PrinterDriver`, `ShippingCalculator`) e rodam em fila, nunca dentro da requisição do usuário.
- Textos da interface em português do Brasil; código, nomes de tabelas e comentários técnicos em inglês.

## Segurança

Não abra issue pública para falhas de segurança. Use o [aviso privado de vulnerabilidade do GitHub](https://github.com/fernando0319/OpenMenuExpress/security/advisories/new).
