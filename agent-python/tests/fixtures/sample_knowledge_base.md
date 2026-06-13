# Base de Conhecimento — Loja Aurora (documento de teste de embedding)

> Documento fixture para exercitar o pipeline RAG do Oryntra: extração → chunking
> (`rag/chunk.py`, alvo ~500 tokens, overlap ~80) → embedding (`rag/embed.py`).
> Foi escrito para gerar **vários chunks** e cobrir fronteiras de parágrafo,
> sentença e palavra. Cada seção carrega fatos distintos para validar retrieval:
> faça uma pergunta e confira se o chunk correto volta no topo.
>
> **Queries sugeridas para validar a busca semântica:**
> 1. "Qual o prazo de entrega para a região Sul?" → deve casar *Prazos de entrega*
> 2. "Como peço reembolso de um produto com defeito?" → *Trocas e reembolsos*
> 3. "Vocês parcelam no cartão? Em quantas vezes?" → *Pagamentos*
> 4. "O atendimento funciona no fim de semana?" → *Horário de atendimento*
> 5. "Como rastreio meu pedido?" → *Rastreamento*

## Sobre a Loja Aurora

A Loja Aurora é um e-commerce brasileiro especializado em iluminação residencial,
luminárias decorativas e automação de ambientes. Operamos exclusivamente online
desde 2019, com centro de distribuição em Joinville (SC) e um segundo hub em
Campinas (SP). Todo o catálogo é próprio: não trabalhamos com marketplace de
terceiros, o que nos permite controlar qualidade e garantia ponta a ponta.

Nosso suporte é prestado por um agente de IA integrado ao Chatwoot, que resolve a
maioria das dúvidas de primeiro nível e escala para um atendente humano quando o
caso envolve análise de garantia, defeito ou negociação comercial. Este documento
é a fonte de verdade usada pelo agente para responder clientes.

## Horário de atendimento

O atendimento humano funciona de segunda a sexta, das 8h às 18h (horário de
Brasília), e aos sábados das 9h às 13h. Não há atendimento humano aos domingos
nem em feriados nacionais. O agente de IA, por outro lado, responde 24 horas por
dia, 7 dias por semana, inclusive em feriados — então perguntas frequentes,
status de pedido e segunda via de boleto podem ser resolvidos a qualquer momento.

Quando um cliente solicita falar com uma pessoa fora do horário comercial, o
agente registra o pedido e informa que um atendente responderá no próximo dia
útil, sempre na ordem de chegada da fila.

## Prazos de entrega

O prazo de entrega varia conforme a região e é contado em dias úteis após a
confirmação do pagamento. Para a região Sul (PR, SC, RS), o prazo padrão é de 2 a
4 dias úteis. Para o Sudeste (SP, RJ, MG, ES), de 3 a 5 dias úteis. Para o
Centro-Oeste e Nordeste, de 5 a 8 dias úteis. Para a região Norte, de 7 a 12 dias
úteis, já que parte do trajeto depende de modal aéreo ou fluvial.

Pedidos com frete expresso saem do prazo padrão e passam a ter entrega em até 2
dias úteis para capitais do Sul e Sudeste. O frete expresso tem custo adicional
calculado no checkout e não está disponível para todos os CEPs. Produtos sob
encomenda — luminárias personalizadas e projetos de automação — têm prazo
informado caso a caso e não seguem a tabela acima.

## Rastreamento

Assim que o pedido é despachado, o cliente recebe por e-mail e por WhatsApp um
código de rastreio com o link da transportadora. O mesmo código fica disponível
na área "Meus Pedidos" do site. O agente de IA consegue consultar o status atual
do pedido apenas com o número do pedido ou o CPF do titular da compra, sem
necessidade de o cliente procurar o e-mail.

Se o rastreio não atualizar por mais de 3 dias úteis, o caso é tratado como
"possível extravio" e escalado automaticamente para um atendente humano, que
abre uma ocorrência junto à transportadora.

## Pagamentos

Aceitamos cartão de crédito (Visa, Mastercard, Elo e American Express), Pix e
boleto bancário. No cartão de crédito, parcelamos em até 12 vezes, sendo as
primeiras 6 parcelas sem juros e as demais com juros de 1,99% ao mês. O valor
mínimo de parcela é de R$ 50,00, então o número máximo de parcelas pode ser menor
para pedidos de baixo valor.

Pagamentos via Pix têm 5% de desconto aplicado automaticamente no checkout e a
confirmação costuma ser instantânea. Boletos vencem em 2 dias úteis e a
compensação leva até 3 dias úteis após o pagamento; o estoque fica reservado até
o vencimento do boleto. Não aceitamos pagamento na entrega, depósito em conta nem
transferência manual fora da plataforma.

## Trocas e reembolsos

O cliente pode solicitar troca ou devolução em até 7 dias corridos após o
recebimento, conforme o Código de Defesa do Consumidor, para qualquer produto,
mesmo sem defeito (direito de arrependimento). Nesse caso, o produto deve estar
sem uso, na embalagem original e com todos os acessórios. O frete de devolução
por arrependimento é por conta do cliente.

Para produtos com defeito de fabricação, o prazo de garantia legal é de 90 dias e
o frete de devolução é por nossa conta. O cliente abre a solicitação informando o
número do pedido e anexando fotos ou vídeo do defeito; o agente de IA registra o
chamado e escala para análise humana. Aprovado o reembolso, o estorno no cartão
aparece em até duas faturas, e o reembolso via Pix é feito em até 5 dias úteis na
mesma chave usada na compra. Luminárias personalizadas não têm direito a troca por
arrependimento, apenas garantia por defeito, por serem feitas sob medida.

## Garantia estendida

Além da garantia legal, oferecemos garantia estendida opcional de 12 ou 24 meses,
contratada no momento da compra. A garantia estendida cobre defeitos elétricos e
de componentes, mas não cobre danos causados por instalação incorreta, sobretensão
da rede elétrica, exposição à água em luminárias de uso interno, nem desgaste
estético natural. Para acionar a garantia estendida, o cliente usa o mesmo fluxo de
defeito e informa que possui o plano contratado; o agente valida a cobertura pelo
número do pedido.

## Perguntas frequentes

**Posso alterar o endereço de entrega depois de finalizar o pedido?** Sim, desde
que o pedido ainda não tenha sido despachado. Após o despacho, a alteração depende
da transportadora e nem sempre é possível.

**Vocês emitem nota fiscal?** Sim, a NF-e é emitida automaticamente e enviada por
e-mail junto com a confirmação de despacho.

**Fazem entrega para todo o Brasil?** Atendemos todos os estados, mas alguns CEPs
de áreas remotas podem ter restrição de transportadora; o sistema avisa no
checkout caso o CEP não seja atendido.

**O produto chegou errado, e agora?** Abra um chamado informando o número do
pedido e uma foto do item recebido. Tratamos como envio incorreto, com coleta e
reenvio sem custo para o cliente.
