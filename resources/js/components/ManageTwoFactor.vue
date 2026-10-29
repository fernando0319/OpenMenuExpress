<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import { onUnmounted, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import TwoFactorRecoveryCodes from '@/components/TwoFactorRecoveryCodes.vue';
import TwoFactorSetupModal from '@/components/TwoFactorSetupModal.vue';
import { Button } from '@/components/ui/button';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { disable, enable } from '@/routes/two-factor';

export type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

withDefaults(defineProps<Props>(), {
    canManageTwoFactor: false,
    requiresConfirmation: false,
    twoFactorEnabled: false,
});

const { hasSetupData, clearTwoFactorAuthData } = useTwoFactorAuth();
const showSetupModal = ref<boolean>(false);

onUnmounted(() => clearTwoFactorAuthData());
</script>

<template>
    <div v-if="canManageTwoFactor" class="space-y-6">
        <Heading
            variant="small"
            title="Verificação em duas etapas"
            description="Uma camada extra de proteção ao entrar"
        />

        <div
            v-if="!twoFactorEnabled"
            class="flex flex-col items-start justify-start space-y-4"
        >
            <p class="text-sm text-muted-foreground">
                Com a verificação em duas etapas ligada, além da senha será
                pedido um código gerado por um aplicativo autenticador no seu
                celular (Google Authenticator, Microsoft Authenticator, etc.).
            </p>

            <div>
                <Button v-if="hasSetupData" @click="showSetupModal = true">
                    <ShieldCheck />Continuar configuração
                </Button>
                <Form
                    v-else
                    v-bind="enable.form()"
                    @success="showSetupModal = true"
                    #default="{ processing }"
                >
                    <Button type="submit" :disabled="processing">
                        Ativar
                    </Button>
                </Form>
            </div>
        </div>

        <div v-else class="flex flex-col items-start justify-start space-y-4">
            <p class="text-sm text-muted-foreground">
                Ao entrar, será pedido o código que aparece no aplicativo
                autenticador do seu celular.
            </p>

            <div class="relative inline">
                <Form v-bind="disable.form()" #default="{ processing }">
                    <Button
                        variant="destructive"
                        type="submit"
                        :disabled="processing"
                    >
                        Desativar
                    </Button>
                </Form>
            </div>

            <TwoFactorRecoveryCodes />
        </div>

        <TwoFactorSetupModal
            v-model:isOpen="showSetupModal"
            :requiresConfirmation="requiresConfirmation"
            :twoFactorEnabled="twoFactorEnabled"
        />
    </div>
</template>
