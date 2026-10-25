<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{ content: string }>()
type Token = { text: string; bold: boolean }
type Block = { kind: 'p' | 'ul' | 'ol'; lines: string[] }
// Deliberately small Markdown subset. Every token is rendered as escaped Vue text;
// HTML, URLs, code and arbitrary tags are never interpreted.
function tokens(text: string): Token[] {
  return text
    .split(/(\*\*[^*\n]+\*\*)/g)
    .filter(Boolean)
    .map((part) => {
      const bold = /^\*\*[^*\n]+\*\*$/.test(part)
      return { text: bold ? part.slice(2, -2) : part, bold }
    })
}
const blocks = computed(() => {
  const result: Block[] = []
  let current: Block | undefined
  for (const line of props.content.replace(/\r\n?/g, '\n').split('\n')) {
    if (!line.trim()) {
      current = undefined
      continue
    }
    const unordered = /^\s*[-*+]\s+(.+)$/.exec(line)
    const ordered = /^\s*\d+[.)]\s+(.+)$/.exec(line)
    const kind = unordered ? 'ul' : ordered ? 'ol' : 'p'
    if (!current || current.kind !== kind) {
      current = { kind, lines: [] }
      result.push(current)
    }
    current.lines.push(unordered?.[1] ?? ordered?.[1] ?? line)
  }
  return result
})
</script>

<template>
  <div class="progress-note__content">
    <template v-for="(block, index) in blocks" :key="index">
      <p v-if="block.kind === 'p'">
        <template v-for="(token, tokenIndex) in tokens(block.lines.join('\n'))" :key="tokenIndex"
          ><strong v-if="token.bold">{{ token.text }}</strong
          ><template v-else>{{ token.text }}</template></template
        >
      </p>
      <component :is="block.kind" v-else
        ><li v-for="(line, lineIndex) in block.lines" :key="lineIndex">
          <template v-for="(token, tokenIndex) in tokens(line)" :key="tokenIndex"
            ><strong v-if="token.bold">{{ token.text }}</strong
            ><template v-else>{{ token.text }}</template></template
          >
        </li></component
      >
    </template>
  </div>
</template>
