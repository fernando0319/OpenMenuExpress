<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { KeyRound, Trash2, UserPlus } from '@lucide/vue';
import { computed, ref } from 'vue';
import TeamController from '@/actions/App/Http/Controllers/StoreSettings/TeamController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PasswordRequirements from '@/components/PasswordRequirements.vue';
import { index } from '@/routes/store-settings/team';

type Member = {
    id: number;
    name: string;
    login: string;
    role: string;
    roleLabel: string;
    isYou: boolean;
    canManage: boolean;
    canResetPassword: boolean;
};

type RoleOption = { value: string; label: string; description: string };

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Equipe', href: index() }],
    },
});

const props = defineProps<{
    passwordRules: { strong: string; light: string };
    members: Member[];
    roles: RoleOption[];
    supportAccesses: {
        id: number;
        action: string;
        reason: string | null;
        path: string | null;
        at: string | null;
    }[];
}>();

const dateTime = new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
});

const page = usePage();
const memberError = computed(
    () => page.props.errors?.member ?? page.props.errors?.role,
);

// Owners and managers need the strong password; the rest of the team, the lighter one.
function rulesFor(role: string): string {
    return ['owner', 'manager'].includes(role)
        ? props.passwordRules.strong
        : props.passwordRules.light;
}

// Add --------------------------------------------------------------------------

const addOpen = ref(false);
const loginKind = ref<'username' | 'email'>('username');
const addForm = useForm({
    name: '',
    username: '',
    email: '',
    password: '',
    role: 'waiter',
});

const selectedRole = computed(() =>
    props.roles.find((role) => role.value === addForm.role),
);

function startAdd(): void {
    addForm.reset();
    addForm.clearErrors();
    addForm.role = props.roles.some((role) => role.value === 'waiter')
        ? 'waiter'
        : (props.roles[0]?.value ?? '');
    loginKind.value = 'username';
    addOpen.value = true;
}

function add(): void {
    addForm
        .transform((data) => ({
            ...data,
            username: loginKind.value === 'username' ? data.username : '',
            email: loginKind.value === 'email' ? data.email : '',
        }))
        .post(TeamController.store.url(), {
            preserveScroll: true,
            onSuccess: () => {
                addOpen.value = false;
            },
        });
}

// Role ---------------------------------------------------------------------

function changeRole(member: Member, role: string): void {
    router.put(
        TeamController.update.url(member.id),
        { role },
        { preserveScroll: true },
    );
}

function remove(member: Member): void {
    if (
        confirm(`Remover ${member.name} da equipe? O acesso é cortado na hora.`)
    ) {
        router.delete(TeamController.destroy.url(member.id), {
            preserveScroll: true,
        });
    }
}

// Password -----------------------------------------------------------------

const passwordOpen = ref(false);
const passwordFor = ref<Member | null>(null);
const passwordForm = useForm({ password: '' });

function startPassword(member: Member): void {
    passwordFor.value = member;
    passwordForm.reset();
    passwordForm.clearErrors();
    passwordOpen.value = true;
}

function savePassword(): void {
    if (passwordFor.value) {
        passwordForm.put(
            TeamController.resetPassword.url(passwordFor.value.id),
            {
                preserveScroll: true,
                onSuccess: () => {
                    passwordOpen.value = false;
                },
            },
        );
    }
}
</script>

<template>
    <Head title="Equipe" />

    <div class="flex max-w-3xl flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <Heading
                title="Equipe"
                description="Quem trabalha na loja e o que cada um pode fazer."
            />
            <Button class="ml-auto" @click="startAdd">
                <UserPlus />
                Adicionar pessoa
            </Button>
        </div>

        <p
            v-if="memberError"
            class="rounded-lg bg-destructive/10 p-3 text-sm text-destructive"
        >
            {{ memberError }}
        </p>

        <ul class="flex flex-col divide-y rounded-xl border">
            <li
                v-for="member in members"
                :key="member.id"
                class="flex flex-wrap items-center gap-3 p-3 text-sm"
            >
                <div class="min-w-0 flex-1">
                    <p class="font-medium">
                        {{ member.name }}
                        <Badge
                            v-if="member.isYou"
                            variant="secondary"
                            class="ml-1"
                            >Você</Badge
                        >
                    </p>
                    <p class="truncate text-muted-foreground">
                        {{ member.login }}
                    </p>
                </div>

                <NativeSelect
                    v-if="member.canManage"
                    :model-value="member.role"
                    class="w-40"
                    :aria-label="`Papel de ${member.name}`"
                    @update:model-value="changeRole(member, String($event))"
                >
                    <option
                        v-for="role in roles"
                        :key="role.value"
                        :value="role.value"
                    >
                        {{ role.label }}
                    </option>
                </NativeSelect>
                <Badge v-else variant="outline">{{ member.roleLabel }}</Badge>

                <div class="flex gap-1">
                    <Button
                        v-if="member.canResetPassword"
                        variant="ghost"
                        size="icon"
                        :aria-label="`Trocar senha de ${member.name}`"
                        @click="startPassword(member)"
                    >
                        <KeyRound />
                    </Button>
                    <Button
                        v-if="member.canManage"
                        variant="ghost"
                        size="icon"
                        :aria-label="`Remover ${member.name}`"
                        @click="remove(member)"
                    >
                        <Trash2 />
                    </Button>
                </div>
            </li>
        </ul>

        <dl class="grid gap-2 rounded-xl border p-4 text-sm">
            <div v-for="role in roles" :key="role.value">
                <dt class="font-medium">{{ role.label }}</dt>
                <dd class="text-muted-foreground">{{ role.description }}</dd>
            </div>
        </dl>
        <section
            v-if="supportAccesses.length > 0"
            class="rounded-xl border text-sm"
        >
            <h2 class="border-b p-4 font-medium">
                Acessos do suporte da plataforma
            </h2>
            <ul class="divide-y">
                <li
                    v-for="access in supportAccesses"
                    :key="access.id"
                    class="flex flex-col px-4 py-2"
                >
                    <span class="font-medium">{{ access.action }}</span>
                    <span class="text-xs text-muted-foreground">
                        {{
                            access.at
                                ? dateTime.format(new Date(access.at))
                                : ''
                        }}
                        <template v-if="access.reason">
                            · {{ access.reason }}</template
                        >
                        <template v-if="access.path">
                            · {{ access.path }}</template
                        >
                    </span>
                </li>
            </ul>
        </section>
    </div>

    <Dialog v-model:open="addOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Adicionar pessoa</DialogTitle>
                <DialogDescription>
                    A pessoa já entra com o login e a senha que você definir.
                </DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="add">
                <div class="grid gap-2">
                    <Label for="member-name">Nome</Label>
                    <Input
                        id="member-name"
                        v-model="addForm.name"
                        maxlength="255"
                    />
                    <InputError :message="addForm.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="member-role">Papel</Label>
                    <NativeSelect id="member-role" v-model="addForm.role">
                        <option
                            v-for="role in roles"
                            :key="role.value"
                            :value="role.value"
                        >
                            {{ role.label }}
                        </option>
                    </NativeSelect>
                    <p
                        v-if="selectedRole"
                        class="text-xs text-muted-foreground"
                    >
                        {{ selectedRole.description }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label>Login</Label>
                    <div class="grid grid-cols-2 gap-2">
                        <Button
                            type="button"
                            :variant="
                                loginKind === 'username' ? 'default' : 'outline'
                            "
                            @click="loginKind = 'username'"
                        >
                            Usuário
                        </Button>
                        <Button
                            type="button"
                            :variant="
                                loginKind === 'email' ? 'default' : 'outline'
                            "
                            @click="loginKind = 'email'"
                        >
                            E-mail
                        </Button>
                    </div>
                    <Input
                        v-if="loginKind === 'username'"
                        v-model="addForm.username"
                        autocapitalize="none"
                        maxlength="40"
                        placeholder="ex.: joao.garcom"
                        aria-label="Usuário"
                    />
                    <Input
                        v-else
                        v-model="addForm.email"
                        type="email"
                        placeholder="email@exemplo.com"
                        aria-label="E-mail"
                    />
                    <InputError
                        :message="
                            addForm.errors.username ?? addForm.errors.email
                        "
                    />
                    <p
                        v-if="loginKind === 'email'"
                        class="text-xs text-muted-foreground"
                    >
                        Se esse e-mail já tiver conta, a pessoa entra na equipe
                        com a senha que já usa.
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="member-password">Senha inicial</Label>
                    <PasswordInput
                        id="member-password"
                        v-model="addForm.password"
                        autocomplete="new-password"
                    />
                    <InputError :message="addForm.errors.password" />
                    <PasswordRequirements
                        v-if="addForm.password !== ''"
                        :rules="rulesFor(addForm.role)"
                        :password="addForm.password"
                    />
                    <p class="text-xs text-muted-foreground">
                        A pessoa pode trocar depois em Minha conta.
                    </p>
                </div>

                <DialogFooter>
                    <Button type="submit" :disabled="addForm.processing"
                        >Adicionar</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="passwordOpen">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle>Nova senha</DialogTitle>
                <DialogDescription v-if="passwordFor">
                    Para {{ passwordFor.name }} ({{ passwordFor.login }}).
                </DialogDescription>
            </DialogHeader>
            <form class="grid gap-3" @submit.prevent="savePassword">
                <PasswordInput
                    v-model="passwordForm.password"
                    autocomplete="new-password"
                    aria-label="Nova senha"
                />
                <InputError :message="passwordForm.errors.password" />
                <PasswordRequirements
                    :rules="rulesFor(passwordFor?.role ?? 'owner')"
                    :password="passwordForm.password"
                />
                <DialogFooter>
                    <Button type="submit" :disabled="passwordForm.processing"
                        >Salvar</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
