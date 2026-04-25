<?php

declare(strict_types=1);

namespace ReactphpX\Tail;

/**
 * tail-cli 用：暴露当前 inotify 监视中的文件路径（fileToFd 的 key）。
 */
class TailCliTail extends Tail
{
    /** @return list<string> */
    public function getWatchedFilePaths(): array
    {
        return array_keys($this->fileToFd);
    }
}
