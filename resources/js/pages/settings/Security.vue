<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import PasswordRequirements from '@/components/PasswordRequirements.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/security';
import type { Props as ManagePasskeysProps } from '@/components/ManagePasskeys.vue';
import ManagePasskeys from '@/components/ManagePasskeys.vue';
import type { Props as ManageTwoFactorProps } from '@/components/ManageTwoFactor.vue';
import ManageTwoFactor from '@/components/ManageTwoFactor.vue';

// oxfmt-ignore
type Props = {
    passwordRules: string;
} & ManagePasskeysProps &
    ManageTwoFactorProps;

const props = defineProps<Props>();

const password = ref('');
const confirmation = ref('');

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Segurança',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Segurança" />

    <h1 class="sr-only">Segurança</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Trocar senha"
            description="Use uma senha longa e difícil de adivinhar para manter a conta segura"
        />

        <Form
            v-bind="SecurityController.update.form()"
            :options="{
                preserveScroll: true,
            }"
            reset-on-success
            :reset-on-error="[
                'password',
                'password_confirmation',
                'current_password',
            ]"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="current_password">Senha atual</Label>
                <PasswordInput
                    id="current_password"
                    name="current_password"
                    class="mt-1 block w-full"
                    autocomplete="current-password"
                    placeholder="Senha atual"
                />
                <InputError :message="errors.current_password" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Nova senha</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    placeholder="Nova senha"
                    v-model="password"
                    :passwordrules="props.passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Repita a nova senha</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    placeholder="Repita a nova senha"
                    v-model="confirmation"
                    :passwordrules="props.passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <PasswordRequirements
                :rules="props.passwordRules"
                :password="password"
                :confirmation="confirmation"
            />

            <div class="flex items-center gap-4">
                <Button
                    :disabled="processing"
                    data-test="update-password-button"
                >
                    Salvar
                </Button>
            </div>
        </Form>
    </div>

    <ManageTwoFactor
        :canManageTwoFactor="canManageTwoFactor"
        :requiresConfirmation="requiresConfirmation"
        :twoFactorEnabled="twoFactorEnabled"
    />

    <ManagePasskeys
        :canManagePasskeys="canManagePasskeys"
        :passkeys="passkeys"
    />
</template>
