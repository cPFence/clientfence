// HTML elements the editor reads as HTML (it turns them into formatting, or keeps their text): left as they are.
const HTML = new Set(('a abbr address article aside b bdi bdo blockquote br caption center cite code col colgroup dd del '
  + 'details dfn div dl dt em figcaption figure font footer h1 h2 h3 h4 h5 h6 header hr i img ins kbd li main mark nav ol '
  + 'p pre q s samp section small span strike strong sub summary sup table tbody td tfoot th thead time tr tt u ul var '
  + 'wbr').split(' '));

/**
 * Escapes tag-like text that is not HTML ("Protect <domain>") in Markdown before an editor loads it: the editor drops
 * elements it does not know with their name, and the client area shows HTML as text anyway, so an escaped "<" keeps
 * what visitors see. Real HTML tags, code, autolinks (<https://...>, <me@example.com>) and an escaped "<" stay as they are.
 */
export function escapeTags(markdown: string): string {
  // A code fence, a code span of any unescaped backtick run (closed by the same run), a link target in <...>, or a tag
  // name after an unescaped "<".
  return markdown.replace(
    /(```[\s\S]*?```|~~~[\s\S]*?~~~|(?<!\\)(`+)[\s\S]*?(?<!`)\2(?!`)|\]\(<[^>\n]*>)|(?<!\\)<(\/?)([A-Za-z][\w-]*)(?![\w+.-]*:)(?![^\s<>@]*@)/g,
    (match, code: string | undefined, ticks: string | undefined, slash: string, name: string) => (
      code !== undefined || HTML.has(name.toLowerCase()) ? match : `\\<${slash}${name}`
    ),
  );
}
