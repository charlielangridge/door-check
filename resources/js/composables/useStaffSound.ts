import {
    inject,
    onBeforeUnmount,
    onMounted,
    provide,
    readonly,
    ref,
    type InjectionKey,
} from 'vue';

function createStaffSound() {
    const enabled = ref(true);
    let audio: AudioContext | null = null;

    async function unlock(): Promise<void> {
        if (!enabled.value) return;
        try {
            audio ??= new AudioContext();
            if (audio.state === 'suspended') await audio.resume();
        } catch {
            // Browsers may require a user gesture before audio can start.
        }
    }

    function toggle(): void {
        enabled.value = !enabled.value;
        if (enabled.value) void unlock();
    }

    function chime(): void {
        if (!enabled.value || !audio || audio.state !== 'running') return;
        const oscillator = audio.createOscillator();
        const gain = audio.createGain();
        oscillator.connect(gain);
        gain.connect(audio.destination);
        oscillator.frequency.value = 660;
        gain.gain.setValueAtTime(0.1, audio.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, audio.currentTime + 0.45);
        oscillator.onended = () => {
            oscillator.disconnect();
            gain.disconnect();
        };
        oscillator.start();
        oscillator.stop(audio.currentTime + 0.45);
    }

    onMounted(() => {
        void unlock();
        window.addEventListener('pointerdown', unlock);
        window.addEventListener('keydown', unlock);
    });
    onBeforeUnmount(() => {
        window.removeEventListener('pointerdown', unlock);
        window.removeEventListener('keydown', unlock);
        void audio?.close();
    });

    return { enabled: readonly(enabled), toggle, chime };
}

const staffSoundKey: InjectionKey<ReturnType<typeof createStaffSound>> =
    Symbol('staffSound');

export function provideStaffSound(): void {
    provide(staffSoundKey, createStaffSound());
}

export function useStaffSound() {
    const sound = inject(staffSoundKey);
    if (!sound) throw new Error('Staff sound requires StaffLayout.');
    return sound;
}
