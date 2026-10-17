<script setup>
import { Head } from "@inertiajs/vue3";
import { ref, onBeforeUnmount } from "vue";

const url = ref("");
const preview = ref(null);
const download = ref(null);
const loadingPreview = ref(false);
const starting = ref(false);
const error = ref("");
let pollTimer = null;
let polling = false;

const csrf =
    document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute("content") ?? "";

async function request(path, body = null) {
    const response = await fetch(path, {
        method: body ? "POST" : "GET",
        headers: {
            Accept: "application/json",
            ...(body
                ? {
                      "Content-Type": "application/json",
                      "X-CSRF-TOKEN": csrf,
                  }
                : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
        credentials: "same-origin",
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const validationMessage = data.errors
            ? Object.values(data.errors).flat()[0]
            : null;

        throw new Error(
            validationMessage ||
                data.message ||
                `Request failed (${response.status})`,
        );
    }

    return data;
}

async function getPreview() {
    error.value = "";
    preview.value = null;
    download.value = null;
    stopPolling();

    if (!url.value.trim()) {
        error.value = "প্রথমে একটি URL পেস্ট করো।";
        return;
    }

    loadingPreview.value = true;

    try {
        preview.value = await request("/media/preview", {
            url: url.value.trim(),
        });
    } catch (e) {
        error.value = e.message;
    } finally {
        loadingPreview.value = false;
    }
}

async function startDownload() {
    error.value = "";
    starting.value = true;

    try {
        download.value = await request("/media/download", {
            url: url.value.trim(),
        });

        startPolling();
    } catch (e) {
        error.value = e.message;
    } finally {
        starting.value = false;
    }
}

function startPolling() {
    stopPolling();
    pollStatus();
    pollTimer = setInterval(pollStatus, 1500);
}

async function pollStatus() {
    if (!download.value?.id || polling) return;

    polling = true;

    try {
        const data = await request(`/media/${download.value.id}/status`);

        download.value = data;

        if (["completed", "failed"].includes(data.status)) {
            stopPolling();
        }
    } catch (e) {
        error.value = e.message;
        stopPolling();
    } finally {
        polling = false;
    }
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

onBeforeUnmount(stopPolling);

const formatDuration = (seconds) => {
    if (seconds == null || seconds === "") return "00:00:00";

    const totalSeconds = Math.floor(Number(seconds));

    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const remainingSeconds = totalSeconds % 60;

    return [hours, minutes, remainingSeconds]
        .map((value) => String(value).padStart(2, "0"))
        .join(":");
};
</script>

<template>
    <Head title="Home" />
    <main class="min-h-screen bg-slate-950 px-4 py-16 text-white">
        <section class="mx-auto max-w-2xl">
            <header class="mb-8 text-center">
                <div class="mb-4 text-4xl">⬇️</div>

                <h1 class="text-3xl font-bold sm:text-5xl">
                    Social Media Downloader
                </h1>

                <p class="mt-4 text-slate-300">
                    Paste a supported media URL to preview and download
                    authorized content.
                </p>
            </header>

            <form
                class="flex flex-col gap-3 rounded-2xl border border-slate-700 bg-slate-900 p-4 sm:flex-row"
                @submit.prevent="getPreview"
            >
                <input
                    v-model="url"
                    type="url"
                    required
                    placeholder="Paste media URL here..."
                    class="min-w-0 flex-1 rounded-lg border border-slate-700 bg-slate-800 p-3 text-white outline-none focus:border-blue-500"
                />

                <button
                    type="submit"
                    :disabled="loadingPreview"
                    class="rounded-lg bg-blue-600 px-5 py-3 font-semibold disabled:opacity-50"
                >
                    {{ loadingPreview ? "Loading..." : "Preview" }}
                </button>
            </form>

            <p
                v-if="error"
                role="alert"
                class="mt-4 rounded-lg bg-red-950 p-3 text-red-300"
            >
                {{ error }}
            </p>

            <section
                v-if="preview"
                class="mt-6 overflow-hidden rounded-2xl border border-slate-700 bg-slate-900"
            >
                <img
                    v-if="preview.thumbnail"
                    :src="preview.thumbnail"
                    alt="Media thumbnail"
                    referrerpolicy="no-referrer"
                    class="max-h-80 w-full object-contain"
                />

                <div class="space-y-4 p-5">
                    <span class="text-sm uppercase text-blue-400">
                        {{ preview.platform }}
                    </span>

                    <h2 class="text-xl font-semibold">
                        {{ preview.title }}
                    </h2>

                    <p v-if="preview.duration != null">
                        Duration: {{ formatDuration(preview.duration) }}
                    </p>

                    <button
                        @click="startDownload"
                        :disabled="starting"
                        class="w-full rounded-lg bg-emerald-600 px-5 py-3 font-semibold disabled:opacity-50"
                    >
                        {{ starting ? "Starting..." : "Download" }}
                    </button>
                </div>
            </section>

            <section
                v-if="download"
                class="mt-6 rounded-2xl border border-slate-700 bg-slate-900 p-5"
            >
                <div class="mb-3 flex justify-between gap-4">
                    <span class="font-semibold">
                        {{ download.title || "Your download" }}
                    </span>

                    <span class="text-blue-400">
                        {{ download.progress }}%
                    </span>
                </div>

                <div
                    class="h-3 overflow-hidden rounded-full bg-slate-700"
                    role="progressbar"
                    :aria-valuenow="download.progress"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div
                        class="h-full rounded-full bg-emerald-500 transition-all duration-300"
                        :style="{
                            width: `${download.progress}%`,
                        }"
                    ></div>
                </div>

                <p class="mt-3 text-sm text-slate-300">
                    Status: {{ download.status }}
                </p>

                <p
                    v-if="download.status === 'failed'"
                    class="mt-3 text-red-400"
                >
                    {{ download.error || "Download failed." }}
                </p>

                <a
                    v-if="download.status === 'completed'"
                    :href="download.download_url"
                    class="mt-4 block rounded-lg bg-blue-600 px-5 py-3 text-center font-semibold"
                >
                    Download File
                </a>
            </section>

            <p class="mt-8 text-center text-sm text-slate-400">
                Download only content you own or have permission to save.
                Platform restrictions may apply.
            </p>
        </section>
    </main>
</template>
