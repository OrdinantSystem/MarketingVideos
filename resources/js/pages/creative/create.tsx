import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Aperture } from 'lucide-react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export default function CreateProject() {
    const form = useForm({ name: '', idea: '', start_phase: 'concepts' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/projects');
    }

    return (
        <div className="min-h-screen bg-[#f2f1ec] px-4 py-8 text-[#263d3a] sm:py-14">
            <Head title="New creative project" />
            <main className="mx-auto max-w-2xl">
                <Link
                    href="/"
                    className="inline-flex items-center gap-1.5 text-xs font-medium text-[#6d7874] hover:text-[#2d4541]"
                >
                    <ArrowLeft className="size-3.5" aria-hidden="true" /> Back
                    to workspace
                </Link>
                <div className="mt-8 rounded-2xl border border-[#dddbd4] bg-[#fbfaf7] p-6 shadow-sm sm:p-9">
                    <span className="grid size-10 place-items-center rounded-xl bg-[#173a37] text-white">
                        <Aperture className="size-5" aria-hidden="true" />
                    </span>
                    <p className="mt-7 text-[10px] font-semibold tracking-[0.16em] text-[#d06a42] uppercase">
                        New production chain
                    </p>
                    <h1 className="mt-2 text-3xl font-semibold tracking-[-0.04em]">
                        What should this advertisement become?
                    </h1>
                    <p className="mt-3 max-w-xl text-sm leading-6 text-[#78817d]">
                        Start with a broad idea. The workspace will turn it into
                        inspectable options, one phase at a time.
                    </p>

                    <form onSubmit={submit} className="mt-8 space-y-6">
                        <label className="block">
                            <span className="text-xs font-semibold text-[#3d514d]">
                                Project name
                            </span>
                            <Input
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                required
                                maxLength={80}
                                placeholder="Voice prompts campaign"
                                className="mt-2 bg-white"
                            />
                            {form.errors.name && (
                                <span className="mt-1 block text-xs text-red-600">
                                    {form.errors.name}
                                </span>
                            )}
                        </label>
                        <label className="block">
                            <span className="text-xs font-semibold text-[#3d514d]">
                                Broad idea
                            </span>
                            <textarea
                                value={form.data.idea}
                                onChange={(event) =>
                                    form.setData('idea', event.target.value)
                                }
                                required
                                maxLength={3000}
                                rows={6}
                                placeholder="A short description of the product, audience, and advertisement you have in mind…"
                                className="mt-2 w-full resize-y rounded-lg border border-[#d8d7d0] bg-white px-3 py-3 text-sm leading-6 outline-none placeholder:text-[#a6aba8] focus:border-[#8fa59e]"
                            />
                            {form.errors.idea && (
                                <span className="mt-1 block text-xs text-red-600">
                                    {form.errors.idea}
                                </span>
                            )}
                        </label>
                        <fieldset>
                            <legend className="text-xs font-semibold text-[#3d514d]">
                                Where should the chain begin?
                            </legend>
                            <div className="mt-2 grid gap-3 sm:grid-cols-2">
                                {[
                                    {
                                        value: 'concepts',
                                        title: 'I have the idea',
                                        copy: 'Begin by shaping 3 concepts.',
                                    },
                                    {
                                        value: 'ideas',
                                        title: 'Help me explore',
                                        copy: 'Optional step 0 creates 3 ideas.',
                                    },
                                ].map((option) => (
                                    <label
                                        key={option.value}
                                        className={`cursor-pointer rounded-xl border p-4 ${form.data.start_phase === option.value ? 'border-[#df7145] bg-[#fff5ef]' : 'border-[#dcdbd4] bg-white'}`}
                                    >
                                        <input
                                            type="radio"
                                            name="start_phase"
                                            value={option.value}
                                            checked={
                                                form.data.start_phase ===
                                                option.value
                                            }
                                            onChange={() =>
                                                form.setData(
                                                    'start_phase',
                                                    option.value,
                                                )
                                            }
                                            className="sr-only"
                                        />
                                        <span className="block text-sm font-semibold">
                                            {option.title}
                                        </span>
                                        <span className="mt-1 block text-xs text-[#818986]">
                                            {option.copy}
                                        </span>
                                    </label>
                                ))}
                            </div>
                        </fieldset>
                        <Button
                            type="submit"
                            disabled={form.processing}
                            className="w-full bg-[#294c47] text-white hover:bg-[#203e3a]"
                        >
                            Create workspace <ArrowRight aria-hidden="true" />
                        </Button>
                    </form>
                </div>
            </main>
        </div>
    );
}
