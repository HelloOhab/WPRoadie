/**
 * Reading a password: hidden input on a terminal, or the whole of stdin
 * when piped (`echo "$PW" | wproadie sites add ... --password-stdin`).
 */

export async function readStdin(): Promise<string> {
  const chunks: Buffer[] = [];
  for await (const chunk of process.stdin) {
    chunks.push(typeof chunk === 'string' ? Buffer.from(chunk) : (chunk as Buffer));
  }
  return Buffer.concat(chunks).toString('utf8').split(/\r?\n/)[0]?.trim() ?? '';
}

/** Prompts on stderr and reads a line from the terminal without echoing it. */
export function promptHidden(question: string): Promise<string> {
  const stdin = process.stdin;
  if (!stdin.isTTY) {
    return readStdin();
  }
  return new Promise((resolve, reject) => {
    process.stderr.write(question);
    let value = '';
    stdin.setRawMode(true);
    stdin.resume();
    stdin.setEncoding('utf8');
    const finish = (err?: Error) => {
      stdin.setRawMode(false);
      stdin.pause();
      stdin.removeListener('data', onData);
      process.stderr.write('\n');
      if (err) reject(err);
      else resolve(value.trim());
    };
    const onData = (data: string) => {
      for (const ch of data) {
        if (ch === '\r' || ch === '\n' || ch === '\u0004') {
          finish();
          return;
        }
        if (ch === '\u0003') {
          finish(new Error('Cancelled.'));
          return;
        }
        if (ch === '\u007f' || ch === '\b') {
          value = value.slice(0, -1);
        } else if (ch >= ' ') {
          value += ch;
        }
      }
    };
    stdin.on('data', onData);
  });
}

/** Password from --password-stdin, a piped stdin, or a hidden prompt. */
export async function readPassword(question: string, fromStdin: boolean): Promise<string> {
  const value = fromStdin || !process.stdin.isTTY ? await readStdin() : await promptHidden(question);
  if (!value) throw new Error('No password given.');
  return value;
}
