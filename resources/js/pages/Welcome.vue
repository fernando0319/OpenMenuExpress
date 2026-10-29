<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    BarChart3,
    Bike,
    CalendarClock,
    Check,
    ChefHat,
    CreditCard,
    MessageCircle,
    Pizza,
    Printer,
    QrCode,
    ShoppingBag,
    Smartphone,
    Store,
    Ticket,
    UtensilsCrossed,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import { dashboard, login, register } from '@/routes';

const props = defineProps<{
    contactWhatsapp: string | null;
}>();

const page = usePage();
const signedIn = computed(() => page.props.auth.user !== null);
const whatsappUrl = computed(() =>
    props.contactWhatsapp
        ? `https://wa.me/${props.contactWhatsapp}?text=${encodeURIComponent('Olá! Quero conhecer o OpenMenuExpress.')}`
        : null,
);

const features = [
    {
        icon: Smartphone,
        title: 'Cardápio digital com a sua cara',
        text: 'Logo, cores, fotos, adicionais e promoções. O cliente pede pelo celular, sem baixar aplicativo.',
    },
    {
        icon: MessageCircle,
        title: 'WhatsApp no automático',
        text: 'O cliente recebe a confirmação e cada mudança de status do pedido, com mensagens que você personaliza.',
    },
    {
        icon: Printer,
        title: 'Impressão automática',
        text: 'O pedido sai na impressora térmica da cozinha assim que chega ou é aceito. Sem clicar em nada.',
    },
    {
        icon: ShoppingBag,
        title: 'PDV e controle de caixa',
        text: 'Venda no balcão e por telefone na mesma tela, com abertura, sangria e fechamento de caixa.',
    },
    {
        icon: UtensilsCrossed,
        title: 'Mesas e app do garçom',
        text: 'Comandas por mesa, pedidos direto do celular do garçom e fechamento com taxa de serviço.',
    },
    {
        icon: Bike,
        title: 'Entregas com rastreio',
        text: 'Taxa por bairro, entregadores no app e o cliente acompanhando a entrega pelo mapa.',
    },
    {
        icon: Pizza,
        title: 'Modo pizzaria',
        text: 'Tamanhos, sabores por categoria, meio a meio e bordas, com o preço calculado do jeito da sua casa.',
    },
    {
        icon: CreditCard,
        title: 'Pagamento online direto na sua conta',
        text: 'Pix e cartão pelo Mercado Pago, Pix Sicoob ou Bitcoin. O dinheiro cai na sua conta, não na nossa.',
    },
    {
        icon: Wallet,
        title: 'Financeiro sem planilha',
        text: 'Fornecedores, contas a pagar e a receber, compras parceladas e baixa automática.',
    },
    {
        icon: BarChart3,
        title: 'Relatórios que ajudam a decidir',
        text: 'Faturamento, horários de pico, produtos mais vendidos e melhores clientes, exportáveis para Excel.',
    },
    {
        icon: Ticket,
        title: 'Cupons e banners',
        text: 'Promoções com regras de uso, primeira compra, valor mínimo e destaque no topo do cardápio.',
    },
    {
        icon: CalendarClock,
        title: 'Pedidos agendados',
        text: 'Aceite encomendas para mais tarde ou outro dia, com limite de pedidos por horário.',
    },
];

const steps = [
    {
        title: 'Crie sua loja',
        text: 'Cadastre-se e escolha o endereço do seu cardápio. Leva menos de um minuto.',
    },
    {
        title: 'Monte o cardápio',
        text: 'Produtos, fotos, adicionais, taxas de entrega e horários. Tudo pelo celular ou computador.',
    },
    {
        title: 'Divulgue e venda',
        text: 'Compartilhe o link e o QR Code. Os pedidos chegam no painel, no WhatsApp e na impressora.',
    },
];

const segments = [
    'Hamburguerias',
    'Pizzarias',
    'Lanchonetes',
    'Restaurantes',
    'Docerias',
    'Açaiterias',
    'Bares',
    'Pastelarias',
];

const mockItems = [
    {
        name: 'Smash Duplo',
        desc: 'Pão brioche, 2 carnes, cheddar',
        price: 'R$ 32,90',
    },
    {
        name: 'Combo Família',
        desc: '4 lanches + fritas + refri 2L',
        price: 'R$ 119,90',
    },
    { name: 'Milkshake', desc: 'Chocolate belga 500 ml', price: 'R$ 18,00' },
];

const faqs = [
    {
        q: 'O OpenMenuExpress cobra comissão por pedido?',
        a: 'Não. Você não paga porcentagem sobre cada venda como nos aplicativos de delivery. O cliente pede direto pelo seu cardápio.',
    },
    {
        q: 'Preciso instalar alguma coisa?',
        a: 'Não. O painel funciona no navegador do computador ou do celular. Só a impressão automática usa um pequeno programa gratuito no computador da loja.',
    },
    {
        q: 'Meus clientes precisam baixar aplicativo?',
        a: 'Não. Eles abrem o link ou o QR Code, escolhem os produtos e enviam o pedido em poucos toques.',
    },
    {
        q: 'Como recebo os pagamentos online?',
        a: 'Você conecta a sua conta do Mercado Pago, Sicoob ou BTCPay. O valor vai direto para a sua conta, sem passar pelo OpenMenuExpress.',
    },
    {
        q: 'Funciona para mesa, balcão e delivery ao mesmo tempo?',
        a: 'Sim. Delivery, retirada, mesas e vendas no balcão ficam no mesmo painel, com um único caixa e os mesmos relatórios.',
    },
];

const gradient = 'bg-gradient-to-r from-[#7b5cff] via-[#f45ff0] to-[#ff7a3d]';
</script>

<template>
    <Head title="Cardápio digital, pedidos e gestão para delivery">
        <meta
            name="description"
            content="Cardápio digital, pedidos pelo WhatsApp, impressão automática, PDV, mesas, entregas e financeiro. Sem comissão por pedido."
        />
    </Head>

    <div
        class="min-h-screen bg-white font-display text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100"
    >
        <header
            class="sticky top-0 z-30 border-b border-zinc-200/70 bg-white/80 backdrop-blur dark:border-zinc-800/70 dark:bg-zinc-950/80"
        >
            <div
                class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4"
            >
                <a href="#inicio" class="flex items-center">
                    <img
                        src="/images/logo-menu-express-BW.png"
                        alt="OpenMenuExpress"
                        class="h-4 w-auto sm:h-5"
                    />
                </a>
                <nav
                    class="hidden items-center gap-7 text-sm text-zinc-600 md:flex dark:text-zinc-400"
                >
                    <a
                        href="#recursos"
                        class="hover:text-zinc-900 dark:hover:text-white"
                        >Recursos</a
                    >
                    <a
                        href="#como-funciona"
                        class="hover:text-zinc-900 dark:hover:text-white"
                        >Como funciona</a
                    >
                    <a
                        href="#duvidas"
                        class="hover:text-zinc-900 dark:hover:text-white"
                        >Dúvidas</a
                    >
                </nav>
                <div class="flex items-center gap-2">
                    <Link
                        v-if="signedIn"
                        :href="dashboard()"
                        class="rounded-full bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900"
                    >
                        Ir para o painel
                    </Link>
                    <template v-else>
                        <Link
                            :href="login()"
                            class="rounded-full px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                        >
                            Entrar
                        </Link>
                        <Link
                            :href="register()"
                            :class="[
                                gradient,
                                'hidden rounded-full px-4 py-2 text-sm font-semibold text-white shadow-md shadow-fuchsia-500/20 hover:opacity-95 sm:inline-block',
                            ]"
                        >
                            Criar minha loja
                        </Link>
                    </template>
                </div>
            </div>
        </header>

        <section id="inicio" class="relative overflow-hidden">
            <div
                class="pointer-events-none absolute inset-0 bg-[radial-gradient(60%_50%_at_15%_10%,rgba(123,92,255,0.18),transparent),radial-gradient(50%_45%_at_90%_20%,rgba(255,122,61,0.16),transparent),radial-gradient(45%_40%_at_60%_90%,rgba(244,95,240,0.12),transparent)]"
            />
            <div
                class="relative mx-auto grid max-w-6xl grid-cols-1 items-center gap-14 px-4 pt-14 pb-20 lg:grid-cols-[1.1fr_0.9fr] lg:pt-20"
            >
                <div class="flex flex-col gap-6">
                    <span
                        class="inline-flex w-fit items-center gap-2 rounded-full border border-fuchsia-200 bg-white/70 px-3 py-1 text-xs font-medium text-fuchsia-700 dark:border-fuchsia-900 dark:bg-zinc-900/60 dark:text-fuchsia-300"
                    >
                        <span class="size-1.5 rounded-full bg-fuchsia-500" />
                        Sem comissão por pedido
                    </span>
                    <h1
                        class="text-4xl leading-[1.1] font-semibold tracking-tight sm:text-5xl lg:text-6xl"
                    >
                        Seu delivery vendendo mais, com
                        <span
                            :class="[gradient, 'bg-clip-text text-transparent']"
                            >tudo em um só lugar</span
                        >.
                    </h1>
                    <p
                        class="max-w-xl text-lg text-zinc-600 dark:text-zinc-400"
                    >
                        Cardápio digital, pedidos no WhatsApp, impressão
                        automática, PDV, mesas, entregas e financeiro. Simples
                        de usar no celular e pronto em minutos.
                    </p>
                    <div class="flex flex-col gap-3 sm:flex-row">
                        <Link
                            :href="signedIn ? dashboard() : register()"
                            :class="[
                                gradient,
                                'inline-flex items-center justify-center gap-2 rounded-full px-6 py-3 text-base font-semibold text-white shadow-lg shadow-fuchsia-500/25 hover:opacity-95',
                            ]"
                        >
                            {{
                                signedIn
                                    ? 'Ir para o painel'
                                    : 'Criar minha loja grátis'
                            }}
                            <ArrowRight class="size-4" />
                        </Link>
                        <a
                            :href="whatsappUrl ?? '#recursos'"
                            :target="whatsappUrl ? '_blank' : undefined"
                            rel="noopener"
                            class="inline-flex items-center justify-center gap-2 rounded-full border border-zinc-300 px-6 py-3 text-base font-medium hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-900"
                        >
                            <MessageCircle v-if="whatsappUrl" class="size-4" />
                            {{
                                whatsappUrl
                                    ? 'Falar com a gente'
                                    : 'Ver recursos'
                            }}
                        </a>
                    </div>
                    <ul
                        class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-zinc-600 dark:text-zinc-400"
                    >
                        <li
                            v-for="item in [
                                'Sem taxa por venda',
                                'Cliente pede sem baixar app',
                                'Dinheiro direto na sua conta',
                            ]"
                            :key="item"
                            class="flex items-center gap-2"
                        >
                            <Check class="size-4 text-emerald-500" />
                            {{ item }}
                        </li>
                    </ul>
                </div>

                <div
                    class="relative mx-auto w-full max-w-sm"
                    aria-hidden="true"
                >
                    <div
                        class="absolute -inset-6 rounded-[3rem] bg-gradient-to-br from-[#7b5cff]/25 via-[#f45ff0]/20 to-[#ff7a3d]/25 blur-2xl"
                    />
                    <div
                        class="relative rounded-[2.5rem] border border-zinc-200 bg-white p-3 shadow-2xl dark:border-zinc-800 dark:bg-zinc-900"
                    >
                        <div
                            class="overflow-hidden rounded-[2rem] bg-zinc-50 dark:bg-zinc-950"
                        >
                            <div
                                :class="[
                                    gradient,
                                    'px-5 pt-6 pb-10 text-white',
                                ]"
                            >
                                <div class="flex items-center gap-3">
                                    <div
                                        class="flex size-11 items-center justify-center rounded-2xl bg-white/90"
                                    >
                                        <ChefHat
                                            class="size-6 text-[#f45ff0]"
                                        />
                                    </div>
                                    <div>
                                        <p class="font-semibold">
                                            Burger da Praça
                                        </p>
                                        <p class="text-xs text-white/80">
                                            Aberto · entrega em 30–45 min
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="-mt-6 flex flex-col gap-3 px-4 pb-5">
                                <div
                                    v-for="item in mockItems"
                                    :key="item.name"
                                    class="flex items-center gap-3 rounded-2xl bg-white p-3 shadow-sm dark:bg-zinc-900"
                                >
                                    <div
                                        class="size-12 shrink-0 rounded-xl bg-gradient-to-br from-orange-200 to-fuchsia-200 dark:from-orange-900/60 dark:to-fuchsia-900/60"
                                    />
                                    <div class="min-w-0 flex-1">
                                        <p
                                            class="truncate text-sm font-semibold"
                                        >
                                            {{ item.name }}
                                        </p>
                                        <p
                                            class="truncate text-xs text-zinc-500"
                                        >
                                            {{ item.desc }}
                                        </p>
                                    </div>
                                    <p class="text-sm font-semibold">
                                        {{ item.price }}
                                    </p>
                                </div>
                                <div
                                    class="mt-1 flex items-center justify-between rounded-2xl bg-zinc-900 px-4 py-3 text-sm text-white dark:bg-white dark:text-zinc-900"
                                >
                                    <span>Ver sacola · 2 itens</span>
                                    <span class="font-semibold">R$ 51,00</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        class="absolute -top-7 -left-10 hidden items-center gap-3 rounded-2xl border border-zinc-200 bg-white px-4 py-3 shadow-xl sm:flex dark:border-zinc-800 dark:bg-zinc-900"
                    >
                        <span
                            class="flex size-9 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-950"
                        >
                            <MessageCircle class="size-4" />
                        </span>
                        <div>
                            <p class="text-xs text-zinc-500">WhatsApp</p>
                            <p class="text-sm font-semibold">
                                Pedido #1042 aceito
                            </p>
                        </div>
                    </div>
                    <div
                        class="absolute -right-8 -bottom-7 hidden items-center gap-3 rounded-2xl border border-zinc-200 bg-white px-4 py-3 shadow-xl sm:flex dark:border-zinc-800 dark:bg-zinc-900"
                    >
                        <span
                            class="flex size-9 items-center justify-center rounded-full bg-orange-100 text-orange-600 dark:bg-orange-950"
                        >
                            <Printer class="size-4" />
                        </span>
                        <div>
                            <p class="text-xs text-zinc-500">Cozinha</p>
                            <p class="text-sm font-semibold">Impresso agora</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section
            class="border-y border-zinc-200 bg-zinc-50 py-6 dark:border-zinc-800 dark:bg-zinc-900/40"
        >
            <div
                class="mx-auto flex max-w-6xl flex-wrap items-center justify-center gap-x-8 gap-y-3 px-4 text-sm text-zinc-500"
            >
                <span
                    class="flex items-center gap-2 font-medium text-zinc-700 dark:text-zinc-300"
                >
                    <Store class="size-4" /> Feito para
                </span>
                <span v-for="segment in segments" :key="segment">{{
                    segment
                }}</span>
            </div>
        </section>

        <section
            id="recursos"
            class="mx-auto max-w-6xl scroll-mt-20 px-4 py-20"
        >
            <div class="mx-auto mb-12 max-w-2xl text-center">
                <h2 class="text-3xl font-semibold tracking-tight sm:text-4xl">
                    Tudo o que a sua loja precisa, sem pagar por pedido
                </h2>
                <p class="mt-4 text-zinc-600 dark:text-zinc-400">
                    Do cardápio ao caixa, do salão à entrega. Um sistema só,
                    feito para o dia a dia de quem vende comida.
                </p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <article
                    v-for="feature in features"
                    :key="feature.title"
                    class="rounded-3xl border border-zinc-200 bg-white p-6 transition hover:-translate-y-0.5 hover:shadow-xl hover:shadow-fuchsia-500/5 dark:border-zinc-800 dark:bg-zinc-900"
                >
                    <span
                        class="mb-4 flex size-11 items-center justify-center rounded-2xl bg-gradient-to-br from-[#7b5cff]/15 via-[#f45ff0]/15 to-[#ff7a3d]/15 text-[#b13cc2] dark:text-[#f08af0]"
                    >
                        <component :is="feature.icon" class="size-5" />
                    </span>
                    <h3 class="font-semibold">{{ feature.title }}</h3>
                    <p
                        class="mt-2 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400"
                    >
                        {{ feature.text }}
                    </p>
                </article>
            </div>
        </section>

        <section class="mx-auto max-w-6xl px-4 pb-20">
            <div class="grid gap-4 lg:grid-cols-2">
                <div
                    class="relative overflow-hidden rounded-3xl bg-zinc-900 p-8 text-white sm:p-10"
                >
                    <div
                        class="absolute -top-20 -right-20 size-64 rounded-full bg-[#7b5cff]/40 blur-3xl"
                    />
                    <QrCode class="relative mb-6 size-10 text-[#c9b8ff]" />
                    <h3 class="relative text-2xl font-semibold">
                        Seu link, seu cliente, sua margem
                    </h3>
                    <p class="relative mt-3 text-zinc-300">
                        Os pedidos chegam direto pelo seu cardápio, com o nome e
                        o WhatsApp de quem comprou. Você conhece seus melhores
                        clientes e pode chamá-los de volta com cupons.
                    </p>
                </div>
                <div
                    :class="[
                        gradient,
                        'relative overflow-hidden rounded-3xl p-8 text-white sm:p-10',
                    ]"
                >
                    <CreditCard class="mb-6 size-10 text-white/90" />
                    <h3 class="text-2xl font-semibold">
                        Pagamento online sem intermediário
                    </h3>
                    <p class="mt-3 text-white/90">
                        Conecte a sua conta do Mercado Pago, Sicoob ou BTCPay. O
                        cliente paga no Pix ou no cartão e o pedido só entra na
                        fila quando o pagamento é confirmado.
                    </p>
                </div>
            </div>
        </section>

        <section
            id="como-funciona"
            class="scroll-mt-20 bg-zinc-50 py-20 dark:bg-zinc-900/40"
        >
            <div class="mx-auto max-w-6xl px-4">
                <h2
                    class="mb-12 text-center text-3xl font-semibold tracking-tight sm:text-4xl"
                >
                    Comece a vender em três passos
                </h2>
                <ol class="grid gap-6 md:grid-cols-3">
                    <li
                        v-for="(step, index) in steps"
                        :key="step.title"
                        class="rounded-3xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900"
                    >
                        <span
                            :class="[
                                gradient,
                                'mb-4 flex size-10 items-center justify-center rounded-full font-semibold text-white',
                            ]"
                        >
                            {{ index + 1 }}
                        </span>
                        <h3 class="font-semibold">{{ step.title }}</h3>
                        <p
                            class="mt-2 text-sm text-zinc-600 dark:text-zinc-400"
                        >
                            {{ step.text }}
                        </p>
                    </li>
                </ol>
            </div>
        </section>

        <section id="duvidas" class="mx-auto max-w-3xl scroll-mt-20 px-4 py-20">
            <h2
                class="mb-10 text-center text-3xl font-semibold tracking-tight sm:text-4xl"
            >
                Perguntas frequentes
            </h2>
            <div class="flex flex-col gap-3">
                <details
                    v-for="faq in faqs"
                    :key="faq.q"
                    class="group rounded-2xl border border-zinc-200 bg-white px-5 py-4 open:shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
                >
                    <summary
                        class="flex cursor-pointer list-none items-center justify-between gap-4 font-medium"
                    >
                        {{ faq.q }}
                        <span
                            class="text-xl text-zinc-400 transition group-open:rotate-45"
                            >+</span
                        >
                    </summary>
                    <p
                        class="mt-3 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400"
                    >
                        {{ faq.a }}
                    </p>
                </details>
            </div>
        </section>

        <section class="px-4 pb-20">
            <div
                class="relative mx-auto max-w-6xl overflow-hidden rounded-[2rem] bg-zinc-900 px-6 py-14 text-center text-white sm:px-12"
            >
                <div
                    class="absolute inset-0 bg-[radial-gradient(50%_80%_at_20%_0%,rgba(123,92,255,0.45),transparent),radial-gradient(50%_80%_at_80%_100%,rgba(255,122,61,0.4),transparent)]"
                />
                <div class="relative mx-auto max-w-2xl">
                    <img
                        src="/images/logo-menu-express-BW.png"
                        alt=""
                        class="mx-auto mb-6 h-8 w-auto max-w-full sm:h-12"
                    />
                    <h2
                        class="text-3xl font-semibold tracking-tight sm:text-4xl"
                    >
                        Pronto para receber pedidos hoje?
                    </h2>
                    <p class="mt-4 text-zinc-300">
                        Crie sua loja, monte o cardápio e compartilhe o link.
                        Sem fidelidade e sem taxa por pedido.
                    </p>
                    <Link
                        :href="signedIn ? dashboard() : register()"
                        class="mt-8 inline-flex items-center gap-2 rounded-full bg-white px-6 py-3 font-semibold text-zinc-900 hover:bg-zinc-100"
                    >
                        {{
                            signedIn
                                ? 'Ir para o painel'
                                : 'Criar minha loja grátis'
                        }}
                        <ArrowRight class="size-4" />
                    </Link>
                </div>
            </div>
        </section>

        <footer class="border-t border-zinc-200 py-8 dark:border-zinc-800">
            <div
                class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-4 px-4 text-sm text-zinc-500 sm:flex-row"
            >
                <img
                    src="/images/logo-menu-express-BW.png"
                    alt="OpenMenuExpress"
                    class="h-4 w-auto"
                />
                <p>
                    © {{ new Date().getFullYear() }} OpenMenuExpress. Todos os
                    direitos reservados.
                </p>
                <div class="flex gap-4">
                    <Link
                        :href="login()"
                        class="hover:text-zinc-900 dark:hover:text-white"
                        >Entrar</Link
                    >
                    <a
                        v-if="whatsappUrl"
                        :href="whatsappUrl"
                        target="_blank"
                        rel="noopener"
                        class="hover:text-zinc-900 dark:hover:text-white"
                        >Contato</a
                    >
                </div>
            </div>
        </footer>
    </div>
</template>
