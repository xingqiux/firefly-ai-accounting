import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';

export type OutputFormat = 'table' | 'json' | 'raw';

export interface FireflyProfile {
  baseUrl: string;
  token: string;
}

export interface FireflyCliConfig {
  activeProfile?: string;
  defaultFormat: OutputFormat;
  timeout: number;
  profiles: Record<string, FireflyProfile>;
}

export interface ActiveProfile extends FireflyProfile {
  name: string;
}

export interface SetTokenInput {
  profile: string;
  baseUrl: string;
  token: string;
}

const DEFAULT_TIMEOUT_MS = 30000;

export function getDefaultConfigPath(): string {
  return process.env.FIREFLY_CLI_CONFIG ?? join(homedir(), '.config', 'firefly-cli', 'config.json');
}

export function createEmptyConfig(): FireflyCliConfig {
  return {
    activeProfile: undefined,
    defaultFormat: 'table',
    timeout: DEFAULT_TIMEOUT_MS,
    profiles: {},
  };
}

export function redactToken(token?: string): string {
  if (!token) {
    return '';
  }
  if (token.length <= 4) {
    return '*'.repeat(token.length);
  }
  return `${'*'.repeat(token.length - 4)}${token.slice(-4)}`;
}

export class ConfigStore {
  constructor(private readonly path = getDefaultConfigPath()) {}

  getPath(): string {
    return this.path;
  }

  async load(): Promise<FireflyCliConfig> {
    try {
      const raw = await readFile(this.path, 'utf8');
      const parsed = JSON.parse(raw) as Partial<FireflyCliConfig>;
      return normalizeConfig(parsed);
    } catch {
      return createEmptyConfig();
    }
  }

  async save(config: FireflyCliConfig): Promise<void> {
    await mkdir(dirname(this.path), { recursive: true });
    await writeFile(this.path, `${JSON.stringify(normalizeConfig(config), null, 2)}\n`, {
      encoding: 'utf8',
      mode: 0o600,
    });
  }

  async setToken(input: SetTokenInput): Promise<FireflyCliConfig> {
    const config = await this.load();
    const profile = input.profile.trim();
    config.activeProfile = profile;
    config.profiles[profile] = {
      baseUrl: normalizeBaseUrl(input.baseUrl),
      token: input.token,
    };
    await this.save(config);
    return config;
  }

  async useProfile(profile: string): Promise<FireflyCliConfig> {
    const config = await this.load();
    if (!config.profiles[profile]) {
      throw new Error(`Profile "${profile}" does not exist.`);
    }
    config.activeProfile = profile;
    await this.save(config);
    return config;
  }

  async getActiveProfile(profileOverride?: string): Promise<ActiveProfile | undefined> {
    const config = await this.load();
    const name = profileOverride ?? config.activeProfile;
    if (!name) {
      return undefined;
    }
    const profile = config.profiles[name];
    if (!profile) {
      return undefined;
    }
    return { name, ...profile };
  }
}

function normalizeConfig(input: Partial<FireflyCliConfig>): FireflyCliConfig {
  const profiles =
    input.profiles && typeof input.profiles === 'object' && !Array.isArray(input.profiles)
      ? input.profiles
      : {};

  return {
    activeProfile: typeof input.activeProfile === 'string' ? input.activeProfile : undefined,
    defaultFormat: isOutputFormat(input.defaultFormat) ? input.defaultFormat : 'table',
    timeout:
      typeof input.timeout === 'number' && input.timeout > 0 ? input.timeout : DEFAULT_TIMEOUT_MS,
    profiles,
  };
}

function isOutputFormat(value: unknown): value is OutputFormat {
  return value === 'table' || value === 'json' || value === 'raw';
}

function normalizeBaseUrl(baseUrl: string): string {
  return baseUrl.trim().replace(/\/+$/, '');
}
