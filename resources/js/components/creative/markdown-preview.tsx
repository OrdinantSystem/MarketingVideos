import type { CreativeDocument } from '@/types/creative';
import { MetadataInspector } from '@/components/creative/metadata-inspector';

interface MarkdownPreviewProps {
    document: CreativeDocument;
}

function renderLine(line: string): string {
    return line
        .replace(/^[-*]\s+/, '• ')
        .replaceAll('**', '')
        .replace(/`([^`]+)`/g, '$1');
}

export function MarkdownPreview({ document }: MarkdownPreviewProps) {
    return (
        <div className="space-y-4">
            {document.sections.map((section) => (
                <section
                    key={`${document.id}-${section.title}`}
                    className="rounded-xl border border-[#e1dfd8] bg-white p-4"
                >
                    <h3 className="text-[10px] font-semibold tracking-[0.14em] text-[#c4663f] uppercase">
                        {section.title}
                    </h3>
                    <div className="mt-2 space-y-1.5 text-sm leading-6 text-[#4f5d59]">
                        {section.content
                            .split('\n')
                            .filter(Boolean)
                            .map((line, lineIndex) => (
                                <p
                                    key={`${line.slice(0, 24)}-${lineIndex}`}
                                    className={
                                        line.startsWith('**')
                                            ? 'font-medium text-[#2e423f]'
                                            : undefined
                                    }
                                >
                                    {renderLine(line)}
                                </p>
                            ))}
                    </div>
                </section>
            ))}
            <details className="rounded-xl border border-[#e1dfd8] bg-[#f3f2ed]">
                <summary className="cursor-pointer px-4 py-3 text-xs font-medium text-[#65706c]">
                    Inspect structured front matter
                </summary>
                <div className="border-t border-[#deddd6] p-3">
                    <MetadataInspector metadata={document.metadata} />
                </div>
            </details>
            <details className="rounded-xl border border-[#e1dfd8] bg-[#f3f2ed]">
                <summary className="cursor-pointer px-4 py-3 text-xs font-medium text-[#65706c]">
                    Inspect raw Markdown body
                </summary>
                <pre className="creative-scrollbar max-h-80 overflow-auto border-t border-[#deddd6] p-4 font-mono text-[10px] leading-5 whitespace-pre-wrap text-[#5e6965]">
                    {document.body}
                </pre>
            </details>
        </div>
    );
}
