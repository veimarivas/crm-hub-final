import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const inputClass = 'w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 focus:bg-white transition-all';

export default function Integration({ integration, webhookUrl, testResult }) {
    const { flash } = usePage().props;
    const [copied, setCopied] = useState(false);

    const form = useForm({
        wacrm_url: integration?.wacrm_url ?? 'http://localhost:8000',
        wacrm_api_key: '',
        webhook_secret: '',
        is_active: integration?.is_active ?? false,
    });

    const submit = (e) => {
        e.preventDefault();
        form.post(route('settings.integration.update'), { preserveScroll: true });
    };

    const copy = () => {
        navigator.clipboard.writeText(webhookUrl);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <AuthenticatedLayout>
            <Head title="Integración" />

            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div>
                    <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Integración con WhatsApp CRM</h1>
                    <p className="text-sm text-gray-400 mt-1">Conecta tu instancia del wacrm para que los mensajes se vuelvan leads</p>
                </div>

                {flash?.success && <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 shadow-sm">{flash.success}</div>}

                {testResult && (
                    <div className={`rounded-2xl border p-5 shadow-sm ${testResult.ok ? 'border-emerald-200 bg-gradient-to-br from-emerald-50 to-teal-50' : 'border-red-200 bg-gradient-to-br from-red-50 to-rose-50'}`}>
                        {testResult.ok ? (
                            <div className="flex items-center gap-3">
                                <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center text-white shadow-lg">✓</div>
                                <div>
                                    <p className="font-bold text-emerald-900">Conexión exitosa</p>
                                    <p className="text-xs text-emerald-700 mt-0.5">
                                        Cuenta del wacrm: <strong>{testResult.account}</strong> · scopes: {(testResult.scopes ?? []).join(', ')}
                                    </p>
                                </div>
                            </div>
                        ) : (
                            <div className="flex items-center gap-3">
                                <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-red-500 to-rose-600 flex items-center justify-center text-white shadow-lg">✕</div>
                                <div>
                                    <p className="font-bold text-red-900">La conexión falló</p>
                                    <p className="text-xs text-red-700 mt-0.5">{testResult.error}</p>
                                </div>
                            </div>
                        )}
                    </div>
                )}

                <div className="grid gap-6 lg:grid-cols-2 items-start">

                {/* Paso 1 */}
                <form onSubmit={submit} className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div className="p-5 sm:p-6 border-b border-gray-100 flex items-center gap-3">
                        <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-[#045474] to-[#1c486c] flex items-center justify-center text-white font-bold shadow-lg shadow-[#045474]/20">1</div>
                        <div>
                            <h3 className="text-base font-bold text-gray-900">Credenciales del wacrm</h3>
                            <p className="text-xs text-gray-400 mt-0.5">En el wacrm: Equipo y API → crea una API key con scopes contacts, conversations y messages</p>
                        </div>
                    </div>
                    <div className="p-5 sm:p-6 space-y-4">
                        <div>
                            <label className="block text-sm font-semibold text-gray-700 mb-1.5">URL del wacrm</label>
                            <input type="url" value={form.data.wacrm_url} onChange={(e) => form.setData('wacrm_url', e.target.value)} required className={inputClass + ' font-mono'} placeholder="http://localhost:8000" />
                            {form.errors.wacrm_url && <p className="mt-1 text-xs text-red-500 font-medium">{form.errors.wacrm_url}</p>}
                        </div>
                        <div>
                            <label className="block text-sm font-semibold text-gray-700 mb-1.5">
                                API key del wacrm {integration?.has_api_key && <span className="text-gray-400 font-normal">(vacío = conservar la actual)</span>}
                            </label>
                            <input type="password" value={form.data.wacrm_api_key} onChange={(e) => form.setData('wacrm_api_key', e.target.value)} placeholder={integration?.has_api_key ? '••••••••••••' : 'wacrm_live_…'} className={inputClass} />
                            {form.errors.wacrm_api_key && <p className="mt-1 text-xs text-red-500 font-medium">{form.errors.wacrm_api_key}</p>}
                        </div>
                        <div>
                            <label className="block text-sm font-semibold text-gray-700 mb-1.5">
                                Secreto del webhook {integration?.has_webhook_secret && <span className="text-gray-400 font-normal">(vacío = conservar el actual)</span>}
                            </label>
                            <input type="password" value={form.data.webhook_secret} onChange={(e) => form.setData('webhook_secret', e.target.value)} placeholder={integration?.has_webhook_secret ? '••••••••••••' : 'whsec_… (del paso 2)'} className={inputClass} />
                        </div>
                        <label className="flex items-center gap-3 cursor-pointer pt-1">
                            <input type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} className="w-4 h-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" />
                            <div>
                                <p className="text-sm font-semibold text-gray-700">Integración activa</p>
                                <p className="text-xs text-gray-400">Habilita enviar WhatsApp desde los leads y recibir eventos</p>
                            </div>
                        </label>
                    </div>
                    <div className="px-5 sm:px-6 py-4 bg-gray-50/80 border-t border-gray-100 flex items-center justify-between">
                        <button
                            type="button"
                            onClick={() => router.post(route('settings.integration.test'), {}, { preserveScroll: true })}
                            className="px-4 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-all shadow-sm"
                        >
                            Probar conexión
                        </button>
                        <button type="submit" disabled={form.processing} className="px-5 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50 transition-all shadow-lg shadow-emerald-500/20">
                            Guardar
                        </button>
                    </div>
                </form>

                {/* Paso 2 */}
                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div className="p-5 sm:p-6 border-b border-gray-100 flex items-center gap-3">
                        <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center text-white font-bold shadow-lg shadow-amber-500/20">2</div>
                        <div>
                            <h3 className="text-base font-bold text-gray-900">Webhook en el wacrm</h3>
                            <p className="text-xs text-gray-400 mt-0.5">En el wacrm: Equipo y API → Webhooks salientes → crea uno con esta URL</p>
                        </div>
                    </div>
                    <div className="p-5 sm:p-6 space-y-4">
                        <div>
                            <div className="flex items-center justify-between mb-1.5">
                                <label className="block text-sm font-semibold text-gray-700">URL a pegar en el wacrm</label>
                                <button onClick={copy} className="text-xs text-emerald-600 hover:text-emerald-700 font-medium">{copied ? '✓ Copiado' : 'Copiar'}</button>
                            </div>
                            <div className="px-3.5 py-2.5 bg-gray-50 border border-gray-200 rounded-xl font-mono text-xs text-gray-700 select-all break-all">{webhookUrl}</div>
                        </div>
                        <div className="rounded-xl border border-blue-200 bg-blue-50 p-4 text-xs text-blue-800 space-y-1">
                            <p className="font-bold">Pasos en el wacrm:</p>
                            <p>1. Marca los eventos <code className="bg-blue-100 px-1.5 py-0.5 rounded font-mono text-[10px]">message.received</code> y <code className="bg-blue-100 px-1.5 py-0.5 rounded font-mono text-[10px]">contact.created</code>.</p>
                            <p>2. Copia el secreto <code className="bg-blue-100 px-1.5 py-0.5 rounded font-mono text-[10px]">whsec_…</code> que el wacrm muestra UNA vez y pégalo arriba en el paso 1.</p>
                            <p>3. Guarda y activa la integración. Cada WhatsApp nuevo se volverá un lead aquí.</p>
                        </div>
                    </div>
                </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
