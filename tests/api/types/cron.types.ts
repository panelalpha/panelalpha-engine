export interface CronJob {
  hash: string;
  minute: string;
  hour: string;
  month: string;
  command: string;
  /** The Compose service a container project's job runs in; null on PHP hosting. */
  service?: string | null;
  /** OpenAPI response field names */
  day?: string;
  weekday?: string;
  /** Request / legacy field names */
  day_of_month?: string;
  day_of_week?: string;
}

export interface CreateCronJobRequest {
  minute: string;
  hour: string;
  day_of_month: string;
  month: string;
  day_of_week: string;
  command: string;
  /** Required on a container project, refused on PHP hosting. */
  service?: string;
}
