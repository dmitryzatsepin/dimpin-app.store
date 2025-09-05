/// <reference types="vite/client" />

interface Bx24InitialData {
  domain: string;
  member_id: string;
  // Можно добавить другие поля, если PHP их передает
}

interface AppConfig {
  apiBaseUrl: string;
}

interface Window {
  bx24InitialData?: Bx24InitialData;
  appConfig?: AppConfig;
}