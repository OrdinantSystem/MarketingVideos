import { ChevronRight, FileText, Folder, Image, Video } from 'lucide-react';
import type { FileTreeNode } from '@/types/creative';

interface FileTreeProps {
    nodes: FileTreeNode[];
    depth?: number;
}

export function FileTree({ nodes, depth = 0 }: FileTreeProps) {
    return (
        <ul
            className={
                depth === 0
                    ? 'space-y-1'
                    : 'ml-3 border-l border-[#e1dfd8] pl-2'
            }
        >
            {nodes.map((node) => {
                if (node.type === 'directory') {
                    return (
                        <li key={node.path}>
                            <details className="group" open={depth < 1}>
                                <summary className="flex cursor-pointer list-none items-center gap-1.5 rounded-md px-1.5 py-1 text-xs text-[#596460] hover:bg-[#efeee8]">
                                    <ChevronRight
                                        className="size-3 transition group-open:rotate-90"
                                        aria-hidden="true"
                                    />
                                    <Folder
                                        className="size-3.5 text-[#c17a51]"
                                        aria-hidden="true"
                                    />
                                    <span className="truncate">
                                        {node.name}
                                    </span>
                                </summary>
                                {node.children && (
                                    <FileTree
                                        nodes={node.children}
                                        depth={depth + 1}
                                    />
                                )}
                            </details>
                        </li>
                    );
                }

                const Icon = node.name.endsWith('.mp4')
                    ? Video
                    : node.name.match(/\.(png|jpg|jpeg)$/)
                      ? Image
                      : FileText;

                return (
                    <li
                        key={node.path}
                        className="flex items-center gap-1.5 rounded-md px-2 py-1 text-[11px] text-[#79827e]"
                        title={node.path}
                    >
                        <Icon
                            className="size-3 shrink-0 text-[#9ca29f]"
                            aria-hidden="true"
                        />
                        <span className="truncate">{node.name}</span>
                    </li>
                );
            })}
        </ul>
    );
}
