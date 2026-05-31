import { Server } from "@modelcontextprotocol/sdk/server/index.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { CallToolRequestSchema, ListToolsRequestSchema } from "@modelcontextprotocol/sdk/types.js";

const APP_NAME = "hf-space-logs";
const APP_VERSION = "0.1.0";

function requireEnv(name) {
  const value = process.env[name];
  if (!value || value.trim() === "") {
    throw new Error(`Missing required env var: ${name}`);
  }

  return value.trim();
}

function toolInput(request) {
  return request.params?.arguments ?? {};
}

function getSpaceId(input) {
  const spaceId = input.spaceId || process.env.HF_SPACE_ID;
  if (!spaceId || String(spaceId).trim() === "") {
    throw new Error("Missing spaceId. Pass it in arguments or set HF_SPACE_ID.");
  }

  return String(spaceId).trim();
}

async function fetchSseLog({ token, spaceId, kind, timeoutSeconds = 20, maxLines = 250 }) {
  const controller = new AbortController();
  const timeoutMs = Math.max(1, Number(timeoutSeconds)) * 1000;
  const timer = setTimeout(() => controller.abort(), timeoutMs);

  try {
    const [owner, space] = String(spaceId).split("/");
    if (!owner || !space) {
      throw new Error("spaceId must be in the format owner/space.");
    }

    const endpoint = `https://huggingface.co/api/spaces/${encodeURIComponent(owner)}/${encodeURIComponent(space)}/logs/${kind}`;
    const response = await fetch(endpoint, {
      method: "GET",
      headers: {
        Authorization: `Bearer ${token}`,
      },
      signal: controller.signal,
    });

    if (!response.ok) {
      const body = await response.text();
      throw new Error(`HF logs request failed (${response.status}): ${body.slice(0, 400)}`);
    }

    if (!response.body) {
      throw new Error("HF logs response has no body");
    }

    const decoder = new TextDecoder();
    const reader = response.body.getReader();

    let buffer = "";
    const lines = [];

    while (true) {
      const { done, value } = await reader.read();
      if (done) {
        break;
      }

      buffer += decoder.decode(value, { stream: true });
      const chunks = buffer.split(/\r?\n/);
      buffer = chunks.pop() ?? "";

      for (const line of chunks) {
        const trimmed = line.trim();
        if (!trimmed.startsWith("data:")) {
          continue;
        }

        const payload = trimmed.slice(5).trim();
        if (!payload || payload === "[DONE]") {
          continue;
        }

        lines.push(payload);
      }

      if (lines.length > maxLines * 4) {
        lines.splice(0, lines.length - maxLines * 2);
      }
    }

    if (buffer.trim().startsWith("data:")) {
      const payload = buffer.trim().slice(5).trim();
      if (payload && payload !== "[DONE]") {
        lines.push(payload);
      }
    }

    const sliced = lines.slice(-Math.max(1, Number(maxLines)));

    return {
      endpoint,
      kind,
      lineCount: sliced.length,
      text: sliced.join("\n") || "(No log lines received in the selected time window)",
    };
  } finally {
    clearTimeout(timer);
  }
}

function wrapText(text) {
  return {
    content: [
      {
        type: "text",
        text,
      },
    ],
  };
}

const server = new Server(
  {
    name: APP_NAME,
    version: APP_VERSION,
  },
  {
    capabilities: {
      tools: {},
    },
  }
);

server.setRequestHandler(ListToolsRequestSchema, async () => {
  return {
    tools: [
      {
        name: "hf_get_run_logs",
        description: "Get Space container run logs from Hugging Face SSE endpoint.",
        inputSchema: {
          type: "object",
          properties: {
            spaceId: {
              type: "string",
              description: "Space id in the format owner/space. Optional if HF_SPACE_ID is set.",
            },
            timeoutSeconds: {
              type: "number",
              description: "How long to stream logs before returning. Default: 20.",
              minimum: 1,
              maximum: 120,
            },
            maxLines: {
              type: "number",
              description: "Maximum number of lines returned. Default: 250.",
              minimum: 1,
              maximum: 2000,
            },
          },
        },
      },
      {
        name: "hf_get_build_logs",
        description: "Get Space build logs from Hugging Face SSE endpoint.",
        inputSchema: {
          type: "object",
          properties: {
            spaceId: {
              type: "string",
              description: "Space id in the format owner/space. Optional if HF_SPACE_ID is set.",
            },
            timeoutSeconds: {
              type: "number",
              description: "How long to stream logs before returning. Default: 20.",
              minimum: 1,
              maximum: 120,
            },
            maxLines: {
              type: "number",
              description: "Maximum number of lines returned. Default: 250.",
              minimum: 1,
              maximum: 2000,
            },
          },
        },
      },
    ],
  };
});

server.setRequestHandler(CallToolRequestSchema, async (request) => {
  try {
    const token = requireEnv("HF_TOKEN");
    const input = toolInput(request);
    const spaceId = getSpaceId(input);
    const timeoutSeconds = input.timeoutSeconds ?? 20;
    const maxLines = input.maxLines ?? 250;

    if (request.params.name === "hf_get_run_logs") {
      const result = await fetchSseLog({
        token,
        spaceId,
        kind: "run",
        timeoutSeconds,
        maxLines,
      });

      return wrapText(
        `HF ${result.kind} logs (${result.lineCount} lines)\nSpace: ${spaceId}\nEndpoint: ${result.endpoint}\n\n${result.text}`
      );
    }

    if (request.params.name === "hf_get_build_logs") {
      const result = await fetchSseLog({
        token,
        spaceId,
        kind: "build",
        timeoutSeconds,
        maxLines,
      });

      return wrapText(
        `HF ${result.kind} logs (${result.lineCount} lines)\nSpace: ${spaceId}\nEndpoint: ${result.endpoint}\n\n${result.text}`
      );
    }

    return wrapText(`Unknown tool: ${request.params.name}`);
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return wrapText(`Error: ${message}`);
  }
});

const transport = new StdioServerTransport();
await server.connect(transport);
