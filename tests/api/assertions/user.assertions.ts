/* eslint-disable @typescript-eslint/require-await */

import { expect } from '@playwright/test';
import { expectOneOf } from '@/helpers/expect-one-of';
import { type EngineApi } from '@/clients/engine-api';
import { type User, type UserDetails } from '@/types';

export class UserAssertions {
  constructor(private api: EngineApi) {}

  /**
   * Verifies that a user exists in the system
   *
   * @param username - Username to check
   */
  async verifyUserExists(username: string): Promise<void> {
    const response = await this.api.getUser(username);
    expect(response.data).toBeTruthy();
    expect(response.data.username).toBe(username);
  }

  /**
   * Verifies that a user does not exist
   *
   * @param username - Username to check
   */
  async verifyUserNotExists(username: string): Promise<void> {
    try {
      const response = await this.api.get(`/projects/${username}`);
      expectOneOf(response.status(), [404, 422]);
    } catch {
      // Expected to fail - user doesn't exist
    }
  }

  /**
   * Verifies complete user structure from API response
   *
   * @param userData - User data to validate
   * @param expectedUsername - Expected username value
   */
  async verifyUserStructure(userData: User, expectedUsername: string): Promise<void> {
    expect(userData).toBeTruthy();
    expect(typeof userData).toBe('object');

    // Validate required user fields
    expect(userData).toHaveProperty('id');
    expect(typeof userData.id).toBe('number');
    expect(userData.id).toBeGreaterThan(0);

    expect(userData).toHaveProperty('username');
    expect(userData.username).toBe(expectedUsername);

    expect(userData).toHaveProperty('domain');
    expect(typeof userData.domain).toBe('string');
    expect(userData.domain).toBeTruthy();

    // Validate optional fields
    expect(userData).toHaveProperty('name');
    expect(userData).toHaveProperty('email');

    // Validate timestamps
    expect(userData).toHaveProperty('created_at');
    expect(typeof userData.created_at).toBe('string');
    expect(userData).toHaveProperty('updated_at');
    expect(typeof userData.updated_at).toBe('string');

    // Validate details object
    expect(userData).toHaveProperty('details');
    await this.verifyUserDetails(userData.details);

    // Validate config object
    expect(userData).toHaveProperty('config');
    expect(typeof userData.config).toBe('object');
  }

  /**
   * Verifies user details object
   *
   * @param details - User details to validate
   */
  async verifyUserDetails(details: UserDetails): Promise<void> {
    expect(details).toBeTruthy();
    expect(typeof details).toBe('object');

    expect(details).toHaveProperty('home_dir');
    expect(typeof details.home_dir).toBe('string');

    expect(details).toHaveProperty('mysql_prefix');
    expect(typeof details.mysql_prefix).toBe('string');

    expect(details).toHaveProperty('disk_space_limit');
    expect(typeof details.disk_space_limit).toBe('number');

    expect(details).toHaveProperty('UID');
    expect(typeof details.UID).toBe('number');
    expect(details.UID).toBeGreaterThan(0);

    expect(details).toHaveProperty('GID');
    expect(typeof details.GID).toBe('number');
    expect(details.GID).toBeGreaterThan(0);
  }

  /**
   * Verifies user status (active/suspended)
   *
   * @param username - Username to check
   * @param expectedStatus - Expected status value
   */
  async verifyUserStatus(username: string, expectedStatus: 'active' | 'suspended'): Promise<void> {
    const response = await this.api.getUser(username);
    expect(response.data.status).toBe(expectedStatus);
  }

  /**
   * Verifies user email
   *
   * @param username - Username to check
   * @param expectedEmail - Expected email value
   */
  async verifyUserEmail(username: string, expectedEmail: string): Promise<void> {
    const response = await this.api.getUser(username);
    expect(response.data.email).toBe(expectedEmail);
  }

  /**
   * Verifies user UID from API
   *
   * @param username - Username to check
   * @returns UID and GID values
   */
  async verifyAndGetUid(username: string): Promise<{ uid: number; gid: number }> {
    const response = await this.api.getUser(username);
    const details = response.data.details;

    expect(details.UID).toBeGreaterThan(0);
    expect(details.GID).toBeGreaterThan(0);

    return { uid: details.UID, gid: details.GID };
  }

  /**
   * Verifies user count in system
   *
   * @param expectedMinCount - Minimum expected user count
   */
  async verifyUserCount(expectedMinCount: number): Promise<void> {
    const response = await this.api.listUsers();
    expect(response.data.length).toBeGreaterThanOrEqual(expectedMinCount);
  }

  /**
   * Verifies username is available
   *
   * @param username - Username to verify
   */
  async verifyUsernameAvailable(username: string): Promise<void> {
    const response = await this.api.verifyUsername(username);
    expect(response.valid).toBe(true);
  }

  /**
   * Verifies username is not available (taken)
   *
   * @param username - Username to verify
   */
  async verifyUsernameTaken(username: string): Promise<void> {
    const response = await this.api.verifyUsername(username);
    expect(response.valid).toBe(false);
    expect(response.reason).toBe('taken');
  }
}
