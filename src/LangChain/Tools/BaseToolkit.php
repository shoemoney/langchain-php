<?php

declare(strict_types=1);

namespace LangChain\Tools;

/**
 * A named group of tools, bundled for an agent.
 *
 * Port of `BaseToolkit` from `@langchain/core/tools`.
 *
 * A toolkit is not itself callable. It exists so that a capability which needs
 * several related tools — a SQL executor, a file-system editor — can be
 * configured once (connection string, root directory) and then handed to an
 * agent as a unit, rather than the caller having to construct and thread N
 * tools with the same configuration.
 */
abstract class BaseToolkit
{
    /**
     * The tools this toolkit provides.
     *
     * @var list<StructuredTool>
     */
    public array $tools;

    /** @param list<StructuredTool> $tools */
    public function __construct(array $tools = [])
    {
        $this->tools = array_values($tools);
    }

    /**
     * The toolkit's tools.
     *
     * @return list<StructuredTool>
     */
    public function getTools(): array
    {
        return $this->tools;
    }
}
