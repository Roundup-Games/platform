--
-- PostgreSQL database dump
--

\restrict 1teZHlzvneGFulRIlu8NXBAVdiUgcrn9wamwkNbYsgYyn1cK5jCUDzhYDARPvk3

-- Dumped from database version 17.10 (Debian 17.10-1.pgdg13+1)
-- Dumped by pg_dump version 18.4

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: pg_trgm; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public;


--
-- Name: EXTENSION pg_trgm; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION pg_trgm IS 'text similarity measurement and index searching based on trigrams';


--
-- Name: CAST (character varying AS uuid); Type: CAST; Schema: -; Owner: -
--

CREATE CAST (character varying AS uuid) WITH INOUT AS IMPLICIT;


--
-- Name: locations_compute_geohash_4(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.locations_compute_geohash_4() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    DECLARE
        v_lat_range double precision[] := ARRAY[-90.0, 90.0];
        v_lng_range double precision[] := ARRAY[-180.0, 180.0];
        v_is_lng boolean := true;
        v_bits integer := 0;
        v_bit_count integer := 0;
        v_mid double precision;
        v_bit_set boolean;
        v_chars text := '0123456789bcdefghjkmnpqrstuvwxyz';
        v_hash text := '';
        v_idx integer;
    BEGIN
        -- If either coordinate is null, clear geohash
        IF NEW.latitude IS NULL OR NEW.longitude IS NULL THEN
            NEW.geohash_4 := NULL;
            RETURN NEW;
        END IF;

        -- Encode 4 characters (4 × 5 bits = 20 bits)
        WHILE length(v_hash) < 4 LOOP
            IF v_is_lng THEN
                v_mid := (v_lng_range[1] + v_lng_range[2]) / 2.0;
                IF NEW.longitude >= v_mid THEN
                    v_bits := v_bits | (16 >> v_bit_count);
                    v_lng_range[1] := v_mid;
                ELSE
                    v_lng_range[2] := v_mid;
                END IF;
            ELSE
                v_mid := (v_lat_range[1] + v_lat_range[2]) / 2.0;
                IF NEW.latitude >= v_mid THEN
                    v_bits := v_bits | (16 >> v_bit_count);
                    v_lat_range[1] := v_mid;
                ELSE
                    v_lat_range[2] := v_mid;
                END IF;
            END IF;

            v_is_lng := NOT v_is_lng;

            IF v_bit_count < 4 THEN
                v_bit_count := v_bit_count + 1;
            ELSE
                v_idx := v_bits + 1; -- PostgreSQL strings are 1-indexed
                v_hash := v_hash || substr(v_chars, v_idx, 1);
                v_bits := 0;
                v_bit_count := 0;
            END IF;
        END LOOP;

        NEW.geohash_4 := v_hash;
        RETURN NEW;
    END;
    $$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: activity_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.activity_logs (
    subject_type character varying(255),
    subject_id character varying(255),
    event_type character varying(255) NOT NULL,
    properties json,
    created_at timestamp(0) without time zone NOT NULL,
    user_id uuid NOT NULL,
    id uuid NOT NULL
);


--
-- Name: attendance_reports; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.attendance_reports (
    id uuid NOT NULL,
    game_id uuid NOT NULL,
    status character varying(255) NOT NULL,
    weight_applied double precision DEFAULT '1'::double precision NOT NULL,
    is_corroborated boolean DEFAULT false NOT NULL,
    quarantined boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    reported_id uuid NOT NULL,
    reporter_id uuid NOT NULL,
    reason text,
    CONSTRAINT attendance_reports_status_check CHECK (((status)::text = ANY ((ARRAY['attended'::character varying, 'no_show'::character varying, 'late_cancel'::character varying, 'excused'::character varying, 'cancelled_early'::character varying])::text[])))
);


--
-- Name: bgg_sync_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.bgg_sync_logs (
    status character varying(255) NOT NULL,
    bgg_ids json,
    items_synced integer DEFAULT 0 NOT NULL,
    items_failed integer DEFAULT 0 NOT NULL,
    error_message text,
    started_at timestamp(0) without time zone NOT NULL,
    completed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    game_system_id uuid,
    id uuid NOT NULL
);


--
-- Name: cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration bigint NOT NULL
);


--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration bigint NOT NULL
);


--
-- Name: campaign_applications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.campaign_applications (
    id uuid NOT NULL,
    campaign_id uuid NOT NULL,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    message text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    user_id uuid NOT NULL,
    CONSTRAINT campaign_applications_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'approved'::character varying, 'rejected'::character varying])::text[])))
);


--
-- Name: campaign_game_system; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.campaign_game_system (
    campaign_id uuid NOT NULL,
    game_system_id uuid NOT NULL
);


--
-- Name: campaign_participants; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.campaign_participants (
    id uuid NOT NULL,
    campaign_id uuid NOT NULL,
    role character varying(255) DEFAULT 'player'::character varying NOT NULL,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    benched_at timestamp(0) without time zone,
    user_id uuid,
    join_source character varying,
    short_link_id bigint,
    removed_by uuid,
    removed_at timestamp(0) without time zone,
    invitee_email character varying(255),
    waitlisted_at timestamp(0) without time zone,
    confirmation_expires_at timestamp(0) without time zone,
    confirmation_attempts integer,
    created_at timestamp(0) without time zone,
    CONSTRAINT campaign_participants_join_source_check CHECK (((join_source)::text = ANY (ARRAY[('friend_invite'::character varying)::text, ('share_link'::character varying)::text, ('application'::character varying)::text, ('email_invite'::character varying)::text, ('short_link'::character varying)::text]))),
    CONSTRAINT campaign_participants_role_check CHECK (((role)::text = ANY ((ARRAY['owner'::character varying, 'player'::character varying, 'invited'::character varying, 'applicant'::character varying])::text[]))),
    CONSTRAINT campaign_participants_status_check CHECK (((status)::text = ANY (ARRAY[('approved'::character varying)::text, ('rejected'::character varying)::text, ('pending'::character varying)::text, ('waitlisted'::character varying)::text, ('benched'::character varying)::text, ('removed'::character varying)::text])))
);


--
-- Name: campaigns; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.campaigns (
    id uuid NOT NULL,
    name jsonb NOT NULL,
    description jsonb NOT NULL,
    recurrence character varying(255) NOT NULL,
    time_of_day character varying(255) NOT NULL,
    session_duration double precision NOT NULL,
    price_per_session double precision,
    language character varying(255) NOT NULL,
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    minimum_requirements json,
    visibility character varying(255) DEFAULT 'public'::character varying NOT NULL,
    safety_rules json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    min_players smallint,
    max_players smallint,
    experience_level character varying(30),
    complexity numeric(3,2),
    vibe_flags json,
    location_id uuid,
    owner_id uuid NOT NULL,
    share_token uuid,
    share_token_expires_at timestamp(0) without time zone,
    location_instructions text,
    bench_mode boolean DEFAULT false NOT NULL,
    game_type character varying(20),
    host_note text,
    CONSTRAINT campaigns_recurrence_check CHECK (((recurrence)::text = ANY ((ARRAY['weekly'::character varying, 'bi-weekly'::character varying, 'monthly'::character varying])::text[]))),
    CONSTRAINT campaigns_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'cancelled'::character varying, 'completed'::character varying])::text[]))),
    CONSTRAINT campaigns_visibility_check CHECK (((visibility)::text = ANY ((ARRAY['public'::character varying, 'protected'::character varying, 'private'::character varying])::text[])))
);


--
-- Name: customers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.customers (
    id bigint NOT NULL,
    billable_type character varying(255) NOT NULL,
    paddle_id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    trial_ends_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    billable_id character varying(36)
);


--
-- Name: customers_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.customers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: customers_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.customers_id_seq OWNED BY public.customers.id;


--
-- Name: escalated_agent_capacity; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_agent_capacity (
    id bigint NOT NULL,
    channel character varying(255) DEFAULT 'default'::character varying NOT NULL,
    max_concurrent integer DEFAULT 10 NOT NULL,
    current_count integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    user_id uuid
);


--
-- Name: escalated_agent_capacity_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_agent_capacity_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_agent_capacity_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_agent_capacity_id_seq OWNED BY public.escalated_agent_capacity.id;


--
-- Name: escalated_agent_profiles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_agent_profiles (
    id bigint NOT NULL,
    agent_type character varying(255) DEFAULT 'full'::character varying NOT NULL,
    max_tickets integer,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    chat_status character varying(255) DEFAULT 'offline'::character varying NOT NULL,
    user_id uuid
);


--
-- Name: escalated_agent_profiles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_agent_profiles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_agent_profiles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_agent_profiles_id_seq OWNED BY public.escalated_agent_profiles.id;


--
-- Name: escalated_agent_skill; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_agent_skill (
    id bigint NOT NULL,
    skill_id bigint NOT NULL,
    proficiency integer DEFAULT 1 NOT NULL,
    user_id uuid
);


--
-- Name: escalated_agent_skill_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_agent_skill_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_agent_skill_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_agent_skill_id_seq OWNED BY public.escalated_agent_skill.id;


--
-- Name: escalated_api_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_api_tokens (
    id bigint NOT NULL,
    tokenable_type character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    token character varying(64) NOT NULL,
    abilities json,
    last_used_at timestamp(0) without time zone,
    last_used_ip character varying(45),
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    tokenable_id uuid
);


--
-- Name: escalated_api_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_api_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_api_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_api_tokens_id_seq OWNED BY public.escalated_api_tokens.id;


--
-- Name: escalated_article_categories; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_article_categories (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    parent_id bigint,
    "position" integer DEFAULT 0 NOT NULL,
    description text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: escalated_article_categories_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_article_categories_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_article_categories_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_article_categories_id_seq OWNED BY public.escalated_article_categories.id;


--
-- Name: escalated_articles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_articles (
    id bigint NOT NULL,
    category_id bigint,
    title character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    body text,
    status character varying(255) DEFAULT 'draft'::character varying NOT NULL,
    view_count integer DEFAULT 0 NOT NULL,
    helpful_count integer DEFAULT 0 NOT NULL,
    not_helpful_count integer DEFAULT 0 NOT NULL,
    published_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    author_id uuid
);


--
-- Name: escalated_articles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_articles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_articles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_articles_id_seq OWNED BY public.escalated_articles.id;


--
-- Name: escalated_attachments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_attachments (
    id bigint NOT NULL,
    attachable_type character varying(255) NOT NULL,
    filename character varying(255) NOT NULL,
    original_filename character varying(255) NOT NULL,
    mime_type character varying(255) NOT NULL,
    size bigint NOT NULL,
    disk character varying(255) NOT NULL,
    path character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    attachable_id uuid
);


--
-- Name: escalated_attachments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_attachments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_attachments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_attachments_id_seq OWNED BY public.escalated_attachments.id;


--
-- Name: escalated_audit_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_audit_logs (
    id bigint NOT NULL,
    action character varying(255) NOT NULL,
    auditable_type character varying(255) NOT NULL,
    auditable_id bigint NOT NULL,
    old_values json,
    new_values json,
    ip_address character varying(255),
    user_agent character varying(255),
    created_at timestamp(0) without time zone,
    user_id uuid
);


--
-- Name: escalated_audit_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_audit_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_audit_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_audit_logs_id_seq OWNED BY public.escalated_audit_logs.id;


--
-- Name: escalated_automations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_automations (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    conditions json NOT NULL,
    actions json NOT NULL,
    active boolean DEFAULT true NOT NULL,
    "position" integer DEFAULT 0 NOT NULL,
    last_run_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: escalated_automations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_automations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_automations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_automations_id_seq OWNED BY public.escalated_automations.id;


--
-- Name: escalated_business_schedules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_business_schedules (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    timezone character varying(255) DEFAULT 'UTC'::character varying NOT NULL,
    is_default boolean DEFAULT false NOT NULL,
    schedule json NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: escalated_business_schedules_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_business_schedules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_business_schedules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_business_schedules_id_seq OWNED BY public.escalated_business_schedules.id;


--
-- Name: escalated_canned_responses; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_canned_responses (
    id bigint NOT NULL,
    title character varying(255) NOT NULL,
    body text NOT NULL,
    category character varying(255),
    is_shared boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    created_by uuid
);


--
-- Name: escalated_canned_responses_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_canned_responses_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_canned_responses_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_canned_responses_id_seq OWNED BY public.escalated_canned_responses.id;


--
-- Name: escalated_chat_routing_rules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_chat_routing_rules (
    id bigint NOT NULL,
    department_id bigint,
    routing_strategy character varying(255) DEFAULT 'round_robin'::character varying NOT NULL,
    offline_behavior character varying(255) DEFAULT 'ticket_fallback'::character varying NOT NULL,
    max_queue_size integer DEFAULT 10 NOT NULL,
    max_concurrent_per_agent integer DEFAULT 5 NOT NULL,
    auto_close_after_minutes integer DEFAULT 30 NOT NULL,
    queue_message text,
    offline_message text,
    is_active boolean DEFAULT true NOT NULL,
    "position" integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: escalated_chat_routing_rules_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_chat_routing_rules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_chat_routing_rules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_chat_routing_rules_id_seq OWNED BY public.escalated_chat_routing_rules.id;


--
-- Name: escalated_chat_sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_chat_sessions (
    id bigint NOT NULL,
    ticket_id bigint NOT NULL,
    customer_session_id character varying(64) NOT NULL,
    status character varying(255) DEFAULT 'waiting'::character varying NOT NULL,
    started_at timestamp(0) without time zone NOT NULL,
    ended_at timestamp(0) without time zone,
    customer_typing_at timestamp(0) without time zone,
    agent_typing_at timestamp(0) without time zone,
    metadata json,
    rating smallint,
    rating_comment text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    agent_id uuid
);


--
-- Name: escalated_chat_sessions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_chat_sessions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_chat_sessions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_chat_sessions_id_seq OWNED BY public.escalated_chat_sessions.id;


--
-- Name: escalated_contacts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_contacts (
    id bigint NOT NULL,
    email character varying(320) NOT NULL,
    name character varying(255),
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    user_id uuid
);


--
-- Name: escalated_contacts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_contacts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_contacts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_contacts_id_seq OWNED BY public.escalated_contacts.id;


--
-- Name: escalated_custom_field_values; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_custom_field_values (
    id bigint NOT NULL,
    custom_field_id bigint NOT NULL,
    entity_type character varying(255) NOT NULL,
    value text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    entity_id uuid
);


--
-- Name: escalated_custom_field_values_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_custom_field_values_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_custom_field_values_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_custom_field_values_id_seq OWNED BY public.escalated_custom_field_values.id;


--
-- Name: escalated_custom_fields; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_custom_fields (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    type character varying(255) NOT NULL,
    context character varying(255) DEFAULT 'ticket'::character varying NOT NULL,
    options json,
    required boolean DEFAULT false NOT NULL,
    placeholder character varying(255),
    description text,
    validation_rules json,
    "position" integer DEFAULT 0 NOT NULL,
    active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    conditions json
);


--
-- Name: escalated_custom_fields_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_custom_fields_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_custom_fields_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_custom_fields_id_seq OWNED BY public.escalated_custom_fields.id;


--
-- Name: escalated_custom_object_records; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_custom_object_records (
    id bigint NOT NULL,
    object_id bigint NOT NULL,
    data json NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: escalated_custom_object_records_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_custom_object_records_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_custom_object_records_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_custom_object_records_id_seq OWNED BY public.escalated_custom_object_records.id;


--
-- Name: escalated_custom_objects; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_custom_objects (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    fields_schema json NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: escalated_custom_objects_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_custom_objects_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_custom_objects_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_custom_objects_id_seq OWNED BY public.escalated_custom_objects.id;


--
-- Name: escalated_delayed_actions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_delayed_actions (
    id bigint NOT NULL,
    workflow_id bigint NOT NULL,
    ticket_id bigint NOT NULL,
    action json NOT NULL,
    remaining_actions json NOT NULL,
    execute_at timestamp(0) without time zone NOT NULL,
    executed boolean DEFAULT false NOT NULL,
    cancelled boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: escalated_delayed_actions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_delayed_actions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_delayed_actions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_delayed_actions_id_seq OWNED BY public.escalated_delayed_actions.id;


--
-- Name: escalated_department_agent; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_department_agent (
    department_id bigint NOT NULL,
    agent_id uuid
);


--
-- Name: escalated_departments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_departments (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    description text,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: escalated_departments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_departments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_departments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_departments_id_seq OWNED BY public.escalated_departments.id;


--
-- Name: escalated_escalation_rules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_escalation_rules (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    trigger_type character varying(255) NOT NULL,
    conditions json NOT NULL,
    actions json NOT NULL,
    "order" integer DEFAULT 0 NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    category character varying(255) DEFAULT 'Uncategorized'::character varying NOT NULL
);


--
-- Name: escalated_escalation_rules_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_escalation_rules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_escalation_rules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_escalation_rules_id_seq OWNED BY public.escalated_escalation_rules.id;


--
-- Name: escalated_holidays; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_holidays (
    id bigint NOT NULL,
    schedule_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    date date NOT NULL,
    recurring boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: escalated_holidays_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_holidays_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_holidays_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_holidays_id_seq OWNED BY public.escalated_holidays.id;


--
-- Name: escalated_import_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_import_jobs (
    id uuid NOT NULL,
    platform character varying(50) NOT NULL,
    status character varying(30) DEFAULT 'pending'::character varying NOT NULL,
    credentials text,
    field_mappings json,
    progress json,
    error_log json,
    started_at timestamp(0) without time zone,
    completed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    user_id uuid
);


--
-- Name: escalated_import_source_maps; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_import_source_maps (
    id bigint NOT NULL,
    import_job_id uuid NOT NULL,
    entity_type character varying(50) NOT NULL,
    source_id character varying(255) NOT NULL,
    escalated_id character varying(255) NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: escalated_import_source_maps_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_import_source_maps_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_import_source_maps_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_import_source_maps_id_seq OWNED BY public.escalated_import_source_maps.id;


--
-- Name: escalated_inbound_emails; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_inbound_emails (
    id bigint NOT NULL,
    message_id character varying(255),
    from_email character varying(255) NOT NULL,
    from_name character varying(255),
    to_email character varying(255) NOT NULL,
    subject character varying(255) NOT NULL,
    body_text text,
    body_html text,
    raw_headers text,
    ticket_id bigint,
    reply_id bigint,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    adapter character varying(255) NOT NULL,
    error_message text,
    processed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: escalated_inbound_emails_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_inbound_emails_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_inbound_emails_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_inbound_emails_id_seq OWNED BY public.escalated_inbound_emails.id;


--
-- Name: escalated_macros; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_macros (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    description character varying(255),
    actions json NOT NULL,
    is_shared boolean DEFAULT true NOT NULL,
    "order" integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    created_by uuid
);


--
-- Name: escalated_macros_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_macros_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_macros_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_macros_id_seq OWNED BY public.escalated_macros.id;


--
-- Name: escalated_mentions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_mentions (
    id bigint NOT NULL,
    reply_id bigint NOT NULL,
    read_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    user_id uuid
);


--
-- Name: escalated_mentions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_mentions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_mentions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_mentions_id_seq OWNED BY public.escalated_mentions.id;


--
-- Name: escalated_permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_permissions (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    "group" character varying(255) NOT NULL,
    description text
);


--
-- Name: escalated_permissions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_permissions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_permissions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_permissions_id_seq OWNED BY public.escalated_permissions.id;


--
-- Name: escalated_plugin_store; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_plugin_store (
    id bigint NOT NULL,
    plugin character varying(50) NOT NULL,
    collection character varying(50) NOT NULL,
    key character varying(255),
    data json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: escalated_plugin_store_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_plugin_store_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_plugin_store_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_plugin_store_id_seq OWNED BY public.escalated_plugin_store.id;


--
-- Name: escalated_plugins; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_plugins (
    id bigint NOT NULL,
    slug character varying(255) NOT NULL,
    is_active boolean DEFAULT false NOT NULL,
    activated_at timestamp(0) without time zone,
    deactivated_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: escalated_plugins_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_plugins_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_plugins_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_plugins_id_seq OWNED BY public.escalated_plugins.id;


--
-- Name: escalated_replies; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_replies (
    id bigint NOT NULL,
    ticket_id bigint NOT NULL,
    author_type character varying(255),
    body text NOT NULL,
    is_internal_note boolean DEFAULT false NOT NULL,
    type character varying(255) DEFAULT 'reply'::character varying NOT NULL,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_pinned boolean DEFAULT false NOT NULL,
    author_id uuid
);


--
-- Name: escalated_replies_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_replies_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_replies_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_replies_id_seq OWNED BY public.escalated_replies.id;


--
-- Name: escalated_role_permission; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_role_permission (
    role_id bigint NOT NULL,
    permission_id bigint NOT NULL
);


--
-- Name: escalated_role_user; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_role_user (
    role_id bigint NOT NULL,
    user_id uuid
);


--
-- Name: escalated_roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_roles (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    description text,
    is_system boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: escalated_roles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_roles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_roles_id_seq OWNED BY public.escalated_roles.id;


--
-- Name: escalated_satisfaction_ratings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_satisfaction_ratings (
    id bigint NOT NULL,
    ticket_id bigint NOT NULL,
    rating smallint NOT NULL,
    comment text,
    rated_by_type character varying(255),
    created_at timestamp(0) without time zone,
    rated_by_id uuid
);


--
-- Name: escalated_satisfaction_ratings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_satisfaction_ratings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_satisfaction_ratings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_satisfaction_ratings_id_seq OWNED BY public.escalated_satisfaction_ratings.id;


--
-- Name: escalated_saved_views; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_saved_views (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    filters json NOT NULL,
    is_shared boolean DEFAULT false NOT NULL,
    is_default boolean DEFAULT false NOT NULL,
    "position" integer DEFAULT 0 NOT NULL,
    icon character varying(255),
    color character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    user_id uuid
);


--
-- Name: escalated_saved_views_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_saved_views_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_saved_views_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_saved_views_id_seq OWNED BY public.escalated_saved_views.id;


--
-- Name: escalated_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_settings (
    id bigint NOT NULL,
    key character varying(255) NOT NULL,
    value text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: escalated_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_settings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_settings_id_seq OWNED BY public.escalated_settings.id;


--
-- Name: escalated_side_conversation_replies; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_side_conversation_replies (
    id bigint NOT NULL,
    side_conversation_id bigint NOT NULL,
    body text NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    author_id uuid
);


--
-- Name: escalated_side_conversation_replies_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_side_conversation_replies_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_side_conversation_replies_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_side_conversation_replies_id_seq OWNED BY public.escalated_side_conversation_replies.id;


--
-- Name: escalated_side_conversations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_side_conversations (
    id bigint NOT NULL,
    ticket_id bigint NOT NULL,
    subject character varying(255) NOT NULL,
    channel character varying(255) DEFAULT 'internal'::character varying NOT NULL,
    status character varying(255) DEFAULT 'open'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    created_by uuid
);


--
-- Name: escalated_side_conversations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_side_conversations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_side_conversations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_side_conversations_id_seq OWNED BY public.escalated_side_conversations.id;


--
-- Name: escalated_skills; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_skills (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: escalated_skills_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_skills_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_skills_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_skills_id_seq OWNED BY public.escalated_skills.id;


--
-- Name: escalated_sla_policies; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_sla_policies (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    is_default boolean DEFAULT false NOT NULL,
    first_response_hours json NOT NULL,
    resolution_hours json NOT NULL,
    business_hours_only boolean DEFAULT false NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: escalated_sla_policies_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_sla_policies_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_sla_policies_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_sla_policies_id_seq OWNED BY public.escalated_sla_policies.id;


--
-- Name: escalated_tags; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_tags (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    color character varying(255) DEFAULT '#6B7280'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: escalated_tags_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_tags_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_tags_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_tags_id_seq OWNED BY public.escalated_tags.id;


--
-- Name: escalated_ticket_activities; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_ticket_activities (
    id bigint NOT NULL,
    ticket_id bigint NOT NULL,
    causer_type character varying(255),
    type character varying(255) NOT NULL,
    properties json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    causer_id uuid
);


--
-- Name: escalated_ticket_activities_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_ticket_activities_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_ticket_activities_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_ticket_activities_id_seq OWNED BY public.escalated_ticket_activities.id;


--
-- Name: escalated_ticket_followers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_ticket_followers (
    ticket_id bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    user_id uuid
);


--
-- Name: escalated_ticket_links; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_ticket_links (
    id bigint NOT NULL,
    parent_ticket_id bigint NOT NULL,
    child_ticket_id bigint NOT NULL,
    link_type character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: escalated_ticket_links_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_ticket_links_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_ticket_links_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_ticket_links_id_seq OWNED BY public.escalated_ticket_links.id;


--
-- Name: escalated_ticket_statuses; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_ticket_statuses (
    id bigint NOT NULL,
    label character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    category character varying(255) NOT NULL,
    color character varying(255) DEFAULT '#6b7280'::character varying NOT NULL,
    description text,
    "position" integer DEFAULT 0 NOT NULL,
    is_default boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: escalated_ticket_statuses_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_ticket_statuses_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_ticket_statuses_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_ticket_statuses_id_seq OWNED BY public.escalated_ticket_statuses.id;


--
-- Name: escalated_ticket_subjects; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_ticket_subjects (
    id bigint NOT NULL,
    ticket_id bigint NOT NULL,
    subject_type character varying(255) NOT NULL,
    subject_id character varying(255) NOT NULL,
    role character varying(255),
    "position" integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: COLUMN escalated_ticket_subjects.role; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.escalated_ticket_subjects.role IS 'Optional label for this attachment, e.g. "reported" or "created"';


--
-- Name: escalated_ticket_subjects_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_ticket_subjects_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_ticket_subjects_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_ticket_subjects_id_seq OWNED BY public.escalated_ticket_subjects.id;


--
-- Name: escalated_ticket_tag; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_ticket_tag (
    ticket_id bigint NOT NULL,
    tag_id bigint NOT NULL
);


--
-- Name: escalated_tickets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_tickets (
    id bigint NOT NULL,
    reference character varying(255) NOT NULL,
    requester_type character varying(255),
    subject character varying(255) NOT NULL,
    description text NOT NULL,
    status character varying(255) DEFAULT 'open'::character varying NOT NULL,
    priority character varying(255) DEFAULT 'medium'::character varying NOT NULL,
    channel character varying(255) DEFAULT 'web'::character varying NOT NULL,
    department_id bigint,
    sla_policy_id bigint,
    first_response_at timestamp(0) without time zone,
    first_response_due_at timestamp(0) without time zone,
    resolution_due_at timestamp(0) without time zone,
    sla_first_response_breached boolean DEFAULT false NOT NULL,
    sla_resolution_breached boolean DEFAULT false NOT NULL,
    resolved_at timestamp(0) without time zone,
    closed_at timestamp(0) without time zone,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    guest_name character varying(255),
    guest_email character varying(255),
    guest_token character varying(64),
    merged_into_id bigint,
    type character varying(255) DEFAULT 'question'::character varying NOT NULL,
    ticket_type character varying(255) DEFAULT 'question'::character varying NOT NULL,
    snoozed_until timestamp(0) without time zone,
    status_before_snooze character varying(255),
    chat_ended_at timestamp(0) without time zone,
    chat_metadata json,
    contact_id bigint,
    requester_id uuid,
    assigned_to uuid,
    snoozed_by uuid
);


--
-- Name: escalated_tickets_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_tickets_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_tickets_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_tickets_id_seq OWNED BY public.escalated_tickets.id;


--
-- Name: escalated_two_factor; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_two_factor (
    id bigint NOT NULL,
    secret text NOT NULL,
    recovery_codes text,
    confirmed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    user_id uuid
);


--
-- Name: escalated_two_factor_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_two_factor_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_two_factor_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_two_factor_id_seq OWNED BY public.escalated_two_factor.id;


--
-- Name: escalated_webhook_deliveries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_webhook_deliveries (
    id bigint NOT NULL,
    webhook_id bigint NOT NULL,
    event character varying(255) NOT NULL,
    payload json,
    response_code smallint,
    response_body text,
    attempts smallint DEFAULT '0'::smallint NOT NULL,
    delivered_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: escalated_webhook_deliveries_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_webhook_deliveries_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_webhook_deliveries_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_webhook_deliveries_id_seq OWNED BY public.escalated_webhook_deliveries.id;


--
-- Name: escalated_webhooks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_webhooks (
    id bigint NOT NULL,
    url character varying(255) NOT NULL,
    events json NOT NULL,
    secret character varying(255),
    active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: escalated_webhooks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_webhooks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_webhooks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_webhooks_id_seq OWNED BY public.escalated_webhooks.id;


--
-- Name: escalated_workflow_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_workflow_logs (
    id bigint NOT NULL,
    workflow_id bigint NOT NULL,
    ticket_id bigint NOT NULL,
    trigger_event character varying(255) NOT NULL,
    conditions_matched boolean NOT NULL,
    actions_executed json,
    error text,
    started_at timestamp(0) without time zone,
    completed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: escalated_workflow_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_workflow_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_workflow_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_workflow_logs_id_seq OWNED BY public.escalated_workflow_logs.id;


--
-- Name: escalated_workflows; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.escalated_workflows (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    trigger_event character varying(255) NOT NULL,
    conditions json NOT NULL,
    actions json NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    "position" integer DEFAULT 0 NOT NULL,
    last_triggered_at timestamp(0) without time zone,
    trigger_count integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    created_by uuid
);


--
-- Name: escalated_workflows_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.escalated_workflows_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: escalated_workflows_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.escalated_workflows_id_seq OWNED BY public.escalated_workflows.id;


--
-- Name: event_announcements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.event_announcements (
    id uuid NOT NULL,
    event_id uuid NOT NULL,
    title jsonb NOT NULL,
    content jsonb NOT NULL,
    is_pinned boolean DEFAULT false NOT NULL,
    is_published boolean DEFAULT false NOT NULL,
    visibility character varying(50) DEFAULT 'all'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    author_id uuid NOT NULL
);


--
-- Name: event_registrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.event_registrations (
    id uuid NOT NULL,
    event_id uuid NOT NULL,
    registration_type character varying(255) NOT NULL,
    division character varying(100),
    status character varying(50) DEFAULT 'pending'::character varying NOT NULL,
    payment_status character varying(50) DEFAULT 'pending'::character varying NOT NULL,
    payment_id character varying(255),
    roster json,
    notes text,
    internal_notes text,
    confirmed_at timestamp(0) without time zone,
    cancelled_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    team_id uuid,
    user_id uuid NOT NULL,
    CONSTRAINT event_registrations_registration_type_check CHECK (((registration_type)::text = ANY ((ARRAY['team'::character varying, 'individual'::character varying])::text[])))
);


--
-- Name: events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.events (
    id uuid NOT NULL,
    name jsonb NOT NULL,
    slug character varying(255) NOT NULL,
    description jsonb,
    short_description jsonb,
    type character varying(255) DEFAULT 'tournament'::character varying NOT NULL,
    status character varying(255) DEFAULT 'draft'::character varying NOT NULL,
    venue_name character varying(255),
    venue_address text,
    city character varying(255),
    country character varying(3),
    postal_code character varying(255),
    start_date date NOT NULL,
    end_date date NOT NULL,
    registration_opens_at timestamp(0) without time zone,
    registration_closes_at timestamp(0) without time zone,
    registration_type character varying(255) DEFAULT 'team'::character varying NOT NULL,
    max_teams integer,
    max_participants integer,
    min_players_per_team integer DEFAULT 7 NOT NULL,
    max_players_per_team integer DEFAULT 21 NOT NULL,
    team_registration_fee integer DEFAULT 0 NOT NULL,
    individual_registration_fee integer DEFAULT 0 NOT NULL,
    early_bird_discount integer,
    early_bird_deadline timestamp(0) without time zone,
    contact_email character varying(255),
    contact_phone character varying(255),
    rules json,
    schedule json,
    divisions json,
    amenities json,
    requirements json,
    logo_url character varying(255),
    banner_url character varying(255),
    is_public boolean DEFAULT true NOT NULL,
    is_featured boolean DEFAULT false NOT NULL,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    language character varying(7),
    location_id uuid,
    organizer_id uuid NOT NULL,
    CONSTRAINT events_registration_type_check CHECK (((registration_type)::text = ANY ((ARRAY['team'::character varying, 'individual'::character varying, 'both'::character varying])::text[]))),
    CONSTRAINT events_status_check CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'published'::character varying, 'registration_open'::character varying, 'registration_closed'::character varying, 'in_progress'::character varying, 'completed'::character varying, 'cancelled'::character varying])::text[]))),
    CONSTRAINT events_type_check CHECK (((type)::text = ANY ((ARRAY['tournament'::character varying, 'league'::character varying, 'camp'::character varying, 'clinic'::character varying, 'social'::character varying, 'other'::character varying])::text[])))
);


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: game_applications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.game_applications (
    id uuid NOT NULL,
    game_id uuid NOT NULL,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    message text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    user_id uuid NOT NULL,
    CONSTRAINT game_applications_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'approved'::character varying, 'rejected'::character varying])::text[])))
);


--
-- Name: game_bulletins; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.game_bulletins (
    id uuid NOT NULL,
    game_id uuid NOT NULL,
    user_id uuid NOT NULL,
    content character varying(280) NOT NULL,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: game_game_system; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.game_game_system (
    game_id uuid NOT NULL,
    game_system_id uuid NOT NULL
);


--
-- Name: game_participants; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.game_participants (
    id uuid NOT NULL,
    game_id uuid NOT NULL,
    role character varying(255) DEFAULT 'player'::character varying NOT NULL,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    attendance_status character varying(255),
    confirmation_expires_at timestamp(0) without time zone,
    waitlisted_at timestamp(0) without time zone,
    attendance_reported_at timestamp(0) without time zone,
    attendance_weight double precision,
    benched_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    confirmation_attempts integer,
    user_id uuid,
    attendance_reported_by uuid,
    join_source character varying,
    short_link_id bigint,
    removed_by uuid,
    removed_at timestamp(0) without time zone,
    invitee_email character varying(255),
    attendance_disputed_at timestamp(0) without time zone,
    approved_at timestamp(0) without time zone,
    promoted_manually boolean DEFAULT false NOT NULL,
    CONSTRAINT game_participants_attendance_status_check CHECK (((attendance_status)::text = ANY ((ARRAY['attended'::character varying, 'no_show'::character varying, 'late_cancel'::character varying, 'excused'::character varying, 'cancelled_early'::character varying])::text[]))),
    CONSTRAINT game_participants_join_source_check CHECK (((join_source)::text = ANY (ARRAY[('friend_invite'::character varying)::text, ('share_link'::character varying)::text, ('application'::character varying)::text, ('email_invite'::character varying)::text, ('short_link'::character varying)::text]))),
    CONSTRAINT game_participants_role_check CHECK (((role)::text = ANY ((ARRAY['owner'::character varying, 'player'::character varying, 'invited'::character varying, 'applicant'::character varying])::text[]))),
    CONSTRAINT game_participants_status_check CHECK (((status)::text = ANY (ARRAY[('approved'::character varying)::text, ('rejected'::character varying)::text, ('pending'::character varying)::text, ('waitlisted'::character varying)::text, ('benched'::character varying)::text, ('removed'::character varying)::text])))
);


--
-- Name: game_system_categories; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.game_system_categories (
    name character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    description text,
    id uuid NOT NULL
);


--
-- Name: game_system_category; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.game_system_category (
    game_system_id uuid NOT NULL,
    game_system_category_id uuid NOT NULL
);


--
-- Name: game_system_category_relations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.game_system_category_relations (
    type character varying(255) DEFAULT 'similar'::character varying,
    category_id uuid NOT NULL,
    related_category_id uuid NOT NULL
);


--
-- Name: game_system_designer; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.game_system_designer (
    game_system_id uuid NOT NULL,
    game_system_designer_id uuid NOT NULL
);


--
-- Name: game_system_designers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.game_system_designers (
    name character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    id uuid NOT NULL
);


--
-- Name: game_system_families; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.game_system_families (
    name character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    id uuid NOT NULL
);


--
-- Name: game_system_family; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.game_system_family (
    game_system_id uuid NOT NULL,
    game_system_family_id uuid NOT NULL
);


--
-- Name: game_system_mechanic; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.game_system_mechanic (
    game_system_id uuid NOT NULL,
    game_system_mechanic_id uuid NOT NULL
);


--
-- Name: game_system_mechanic_relations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.game_system_mechanic_relations (
    type character varying(255) DEFAULT 'similar'::character varying,
    mechanic_id uuid NOT NULL,
    related_mechanic_id uuid NOT NULL
);


--
-- Name: game_system_mechanics; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.game_system_mechanics (
    name character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    description text,
    id uuid NOT NULL
);


--
-- Name: game_system_publisher; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.game_system_publisher (
    game_system_id uuid NOT NULL,
    game_system_publisher_id uuid NOT NULL
);


--
-- Name: game_system_publishers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.game_system_publishers (
    name character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    id uuid NOT NULL
);


--
-- Name: game_systems; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.game_systems (
    name jsonb NOT NULL,
    slug character varying(255) NOT NULL,
    description jsonb,
    images json,
    min_players integer,
    max_players integer,
    optimal_players integer,
    average_play_time integer,
    age_rating character varying(50),
    complexity_rating character varying(50),
    year_released integer,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    bgg_id integer,
    bgg_type character varying(255),
    thumbnail_url character varying(255),
    bgg_average_rating numeric(4,2),
    bgg_bayes_average numeric(4,2),
    bgg_rank integer,
    bgg_users_rated integer,
    bgg_average_weight numeric(4,2),
    bgg_last_synced_at timestamp(0) without time zone,
    type character varying(255) DEFAULT 'boardgame'::character varying NOT NULL,
    source character varying(255),
    source_slug character varying(255),
    creator character varying(255),
    player_range character varying(255),
    sp_rating numeric(3,2),
    sp_review_count integer,
    faq_content text,
    external_links text,
    showcases text,
    instructions text,
    platform_score integer DEFAULT 0 NOT NULL,
    id uuid NOT NULL,
    base_game_id uuid
);


--
-- Name: games; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.games (
    id uuid NOT NULL,
    campaign_id uuid,
    name jsonb NOT NULL,
    date_time timestamp(0) without time zone NOT NULL,
    description jsonb NOT NULL,
    expected_duration double precision NOT NULL,
    price double precision,
    language character varying(50) NOT NULL,
    location json NOT NULL,
    status character varying(255) DEFAULT 'scheduled'::character varying NOT NULL,
    minimum_requirements json,
    visibility character varying(255) DEFAULT 'public'::character varying NOT NULL,
    safety_rules json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    min_players smallint DEFAULT '2'::smallint NOT NULL,
    max_players smallint DEFAULT '6'::smallint NOT NULL,
    experience_level character varying(30),
    complexity numeric(3,2),
    vibe_flags json,
    game_type character varying(255) DEFAULT 'board_game'::character varying NOT NULL,
    reminder_sent_at timestamp(0) without time zone,
    recap text,
    min_reliability_preference numeric(5,2),
    reminder_24h_sent_at timestamp(0) without time zone,
    location_id uuid,
    owner_id uuid NOT NULL,
    share_token uuid,
    share_token_expires_at timestamp(0) without time zone,
    location_instructions text,
    bench_mode boolean DEFAULT false NOT NULL,
    attendance_window_opens_at timestamp(0) without time zone,
    attendance_window_closes_at timestamp(0) without time zone,
    attendance_resolved_at timestamp(0) without time zone,
    attendance_resolution_method character varying(255),
    host_note text,
    CONSTRAINT games_attendance_resolution_method_check CHECK (((attendance_resolution_method)::text = ANY ((ARRAY['early_consensus'::character varying, 'timeout'::character varying, 'manual'::character varying])::text[]))),
    CONSTRAINT games_status_check CHECK (((status)::text = ANY ((ARRAY['scheduled'::character varying, 'canceled'::character varying, 'completed'::character varying])::text[]))),
    CONSTRAINT games_visibility_check CHECK (((visibility)::text = ANY ((ARRAY['public'::character varying, 'protected'::character varying, 'private'::character varying])::text[])))
);


--
-- Name: gm_profiles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.gm_profiles (
    id uuid NOT NULL,
    bio text,
    specializations json,
    slug character varying(255) NOT NULL,
    average_rating numeric(3,2),
    review_count integer DEFAULT 0 NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    user_id uuid NOT NULL
);


--
-- Name: gm_social_links; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.gm_social_links (
    id bigint NOT NULL,
    user_id uuid NOT NULL,
    platform character varying(50) NOT NULL,
    handle character varying(255) NOT NULL,
    instance character varying(255),
    url character varying(500),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: gm_social_links_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.gm_social_links_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: gm_social_links_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.gm_social_links_id_seq OWNED BY public.gm_social_links.id;


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


--
-- Name: jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: linked_accounts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.linked_accounts (
    provider character varying(255) NOT NULL,
    provider_user_id character varying(255) NOT NULL,
    token text,
    refresh_token text,
    token_expires_at timestamp(0) without time zone,
    provider_meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    user_id uuid NOT NULL,
    id uuid NOT NULL
);


--
-- Name: local_subscriptions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.local_subscriptions (
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    starts_at timestamp(0) without time zone,
    ends_at timestamp(0) without time zone,
    canceled_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    user_id uuid NOT NULL,
    id uuid NOT NULL,
    membership_type_id uuid NOT NULL
);


--
-- Name: locations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.locations (
    name character varying(255),
    description text,
    address character varying(255),
    city character varying(255),
    postal_code character varying(20),
    country character varying(3),
    latitude numeric(10,7),
    longitude numeric(10,7),
    place_id character varying(255),
    source character varying(50),
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    geohash_4 character varying(4),
    id uuid NOT NULL,
    is_verified boolean DEFAULT false NOT NULL,
    venue_type character varying(50),
    venue_notes text,
    website_url character varying(500),
    managed_by uuid,
    venue_metadata json,
    slug character varying(255),
    drift_status character varying(20) DEFAULT 'clean'::character varying NOT NULL,
    drift_detected_at timestamp(0) without time zone,
    drift_metadata json,
    average_rating numeric(3,2),
    review_count integer DEFAULT 0 NOT NULL
);


--
-- Name: media; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.media (
    model_type character varying(255) NOT NULL,
    model_id character varying(36) NOT NULL,
    uuid uuid,
    collection_name character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    file_name character varying(255) NOT NULL,
    mime_type character varying(255),
    disk character varying(255) NOT NULL,
    conversions_disk character varying(255),
    size bigint NOT NULL,
    manipulations json NOT NULL,
    custom_properties json NOT NULL,
    generated_conversions json NOT NULL,
    responsive_images json NOT NULL,
    order_column integer,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    id uuid NOT NULL
);


--
-- Name: membership_types; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.membership_types (
    name character varying(255) NOT NULL,
    description character varying(1000),
    price_cents integer NOT NULL,
    duration_months integer NOT NULL,
    status character varying(50) DEFAULT 'active'::character varying NOT NULL,
    paddle_price_id character varying(255),
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    type character varying(255) DEFAULT 'paddle'::character varying NOT NULL,
    id uuid NOT NULL
);


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: model_has_permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.model_has_permissions (
    permission_id bigint NOT NULL,
    model_type character varying(255) NOT NULL,
    team_id character varying(36),
    model_id character varying(36)
);


--
-- Name: model_has_roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.model_has_roles (
    role_id bigint NOT NULL,
    model_type character varying(255) NOT NULL,
    team_id character varying(36),
    model_id character varying(36)
);


--
-- Name: nearby_discovery_views; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.nearby_discovery_views (
    last_discovery_view timestamp(0) without time zone,
    geohash_4 character varying(4),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    user_id uuid NOT NULL,
    id uuid NOT NULL
);


--
-- Name: notifications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notifications (
    id uuid NOT NULL,
    type character varying(255) NOT NULL,
    notifiable_type character varying(255) NOT NULL,
    data jsonb NOT NULL,
    read_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    notifiable_id character varying(36)
);


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.permissions (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    guard_name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: permissions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.permissions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: permissions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.permissions_id_seq OWNED BY public.permissions.id;


--
-- Name: personal_access_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.personal_access_tokens (
    id bigint NOT NULL,
    tokenable_type character varying(255) NOT NULL,
    tokenable_id bigint NOT NULL,
    name text NOT NULL,
    token character varying(64) NOT NULL,
    abilities text,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.personal_access_tokens_id_seq OWNED BY public.personal_access_tokens.id;


--
-- Name: push_subscriptions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.push_subscriptions (
    endpoint character varying(255) NOT NULL,
    p256h_key character varying(255) NOT NULL,
    auth_token character varying(255) NOT NULL,
    user_agent character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    user_id uuid NOT NULL,
    id uuid NOT NULL
);


--
-- Name: reviews; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.reviews (
    id uuid NOT NULL,
    reviewable_type character varying(255) NOT NULL,
    reviewable_id character varying(36) NOT NULL,
    gm_profile_id uuid,
    rating smallint NOT NULL,
    body text,
    proficiency_tags json,
    status character varying(255) DEFAULT 'published'::character varying NOT NULL,
    reported_at timestamp(0) without time zone,
    reply text,
    replied_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    report_reason character varying(255),
    reviewer_id uuid NOT NULL,
    reported_by uuid,
    CONSTRAINT reviews_rating_check CHECK (((rating >= 1) AND (rating <= 5)))
);


--
-- Name: role_has_permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.role_has_permissions (
    permission_id bigint NOT NULL,
    role_id bigint NOT NULL
);


--
-- Name: roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.roles (
    id bigint NOT NULL,
    team_id character varying(36),
    name character varying(255) NOT NULL,
    guard_name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: roles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: roles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.roles_id_seq OWNED BY public.roles.id;


--
-- Name: seo; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.seo (
    id bigint NOT NULL,
    model_type character varying(255) NOT NULL,
    model_id character varying(36),
    description text,
    title character varying(255),
    image character varying(255),
    author character varying(255),
    robots character varying(255),
    canonical_url character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: seo_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.seo_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: seo_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.seo_id_seq OWNED BY public.seo.id;


--
-- Name: session_debriefings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.session_debriefings (
    id uuid NOT NULL,
    game_id uuid NOT NULL,
    tool_type character varying(255) NOT NULL,
    responses json,
    submitted_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    user_id uuid NOT NULL,
    CONSTRAINT session_debriefings_tool_type_check CHECK (((tool_type)::text = ANY ((ARRAY['debriefing'::character varying, 'stars-and-wishes'::character varying])::text[])))
);


--
-- Name: session_zero_confirmations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.session_zero_confirmations (
    id uuid NOT NULL,
    session_zero_survey_id uuid NOT NULL,
    confirmed_at timestamp(0) without time zone,
    user_id uuid
);


--
-- Name: session_zero_surveys; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.session_zero_surveys (
    id uuid NOT NULL,
    gm_profile_id uuid NOT NULL,
    game_id uuid,
    title character varying(255) NOT NULL,
    content json,
    uuid character varying(255) NOT NULL,
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    confirmation_count integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL,
    user_id uuid
);


--
-- Name: short_link_hits; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.short_link_hits (
    id bigint NOT NULL,
    short_link_id bigint NOT NULL,
    ip_address character varying(128),
    referer text,
    user_agent text,
    country_code character varying(2),
    hit_at timestamp(0) without time zone NOT NULL,
    referer_domain character varying(255)
);


--
-- Name: short_link_hits_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.short_link_hits_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: short_link_hits_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.short_link_hits_id_seq OWNED BY public.short_link_hits.id;


--
-- Name: short_links; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.short_links (
    id bigint NOT NULL,
    code character varying(36) NOT NULL,
    url text NOT NULL,
    linkable_type character varying(255) NOT NULL,
    linkable_id character varying(255) NOT NULL,
    user_id uuid,
    label character varying(100),
    purpose character varying(50),
    expires_at timestamp(0) without time zone,
    max_hits integer,
    hit_count integer DEFAULT 0 NOT NULL,
    last_hit_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: short_links_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.short_links_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: short_links_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.short_links_id_seq OWNED BY public.short_links.id;


--
-- Name: subscription_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscription_items (
    id bigint NOT NULL,
    subscription_id bigint NOT NULL,
    product_id character varying(255) NOT NULL,
    price_id character varying(255) NOT NULL,
    status character varying(255) NOT NULL,
    quantity integer NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: subscription_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.subscription_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subscription_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.subscription_items_id_seq OWNED BY public.subscription_items.id;


--
-- Name: subscriptions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscriptions (
    id bigint NOT NULL,
    billable_type character varying(255) NOT NULL,
    type character varying(255) NOT NULL,
    paddle_id character varying(255) NOT NULL,
    status character varying(255) NOT NULL,
    trial_ends_at timestamp(0) without time zone,
    paused_at timestamp(0) without time zone,
    ends_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    billable_id character varying(36)
);


--
-- Name: subscriptions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.subscriptions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subscriptions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.subscriptions_id_seq OWNED BY public.subscriptions.id;


--
-- Name: suppressed_invite_emails; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.suppressed_invite_emails (
    id bigint NOT NULL,
    email_hash character varying(64) NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: suppressed_invite_emails_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.suppressed_invite_emails_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: suppressed_invite_emails_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.suppressed_invite_emails_id_seq OWNED BY public.suppressed_invite_emails.id;


--
-- Name: team_members; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.team_members (
    role character varying(255) DEFAULT 'player'::character varying NOT NULL,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    jersey_number character varying(3),
    "position" character varying(50),
    joined_at timestamp(0) without time zone NOT NULL,
    left_at timestamp(0) without time zone,
    notes text,
    team_id uuid NOT NULL,
    user_id uuid NOT NULL,
    invited_by uuid,
    id uuid NOT NULL,
    CONSTRAINT team_members_role_check CHECK (((role)::text = ANY ((ARRAY['captain'::character varying, 'coach'::character varying, 'player'::character varying, 'substitute'::character varying])::text[]))),
    CONSTRAINT team_members_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'active'::character varying, 'inactive'::character varying, 'removed'::character varying])::text[])))
);


--
-- Name: teams; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.teams (
    name character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    description jsonb,
    city character varying(255),
    country character varying(3),
    logo_url character varying(255),
    primary_color character varying(7),
    secondary_color character varying(7),
    founded_year character varying(4),
    website character varying(255),
    social_links json,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    id uuid NOT NULL,
    created_by uuid NOT NULL,
    language character varying(5)
);


--
-- Name: transactions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.transactions (
    id bigint NOT NULL,
    billable_type character varying(255) NOT NULL,
    paddle_id character varying(255) NOT NULL,
    paddle_subscription_id character varying(255),
    invoice_number character varying(255),
    status character varying(255) NOT NULL,
    total character varying(255) NOT NULL,
    tax character varying(255) NOT NULL,
    currency character varying(3) NOT NULL,
    billed_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    billable_id character varying(36)
);


--
-- Name: transactions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.transactions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: transactions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.transactions_id_seq OWNED BY public.transactions.id;


--
-- Name: user_app_visits; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_app_visits (
    visit_date date NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    user_id uuid NOT NULL,
    id uuid NOT NULL
);


--
-- Name: user_game_system_preferences; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_game_system_preferences (
    preference_type character varying(255) NOT NULL,
    game_system_id uuid NOT NULL,
    user_id uuid NOT NULL,
    CONSTRAINT user_game_system_preferences_preference_type_check CHECK (((preference_type)::text = ANY ((ARRAY['favorite'::character varying, 'avoid'::character varying])::text[])))
);


--
-- Name: user_relationships; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_relationships (
    type character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    user_id uuid NOT NULL,
    related_user_id uuid NOT NULL,
    id uuid NOT NULL,
    CONSTRAINT user_relationships_type_check CHECK (((type)::text = ANY ((ARRAY['follow'::character varying, 'block'::character varying])::text[])))
);


--
-- Name: user_vibe_preferences; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_vibe_preferences (
    vibe_preference_value character varying(255) NOT NULL,
    preference_type character varying(255) NOT NULL,
    user_id uuid NOT NULL,
    CONSTRAINT user_vibe_preferences_preference_type_check CHECK (((preference_type)::text = ANY ((ARRAY['favorite'::character varying, 'avoid'::character varying])::text[])))
);


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255),
    avatar_url character varying(255),
    profile_complete boolean DEFAULT false NOT NULL,
    gender character varying(255),
    pronouns character varying(255),
    phone character varying(255),
    privacy_settings json,
    profile_version integer DEFAULT 1 NOT NULL,
    profile_updated_at timestamp(0) without time zone,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    paddle_id character varying(255),
    trial_ends_at timestamp(0) without time zone,
    password_set_at timestamp(0) without time zone,
    is_disabled boolean DEFAULT false NOT NULL,
    disabled_at timestamp(0) without time zone,
    can_create_public_entries boolean DEFAULT false NOT NULL,
    preferred_language character varying(10),
    location json,
    notification_settings json,
    reliability_score json,
    reliability_computed_at timestamp(0) without time zone,
    location_id uuid,
    id uuid NOT NULL,
    bio text,
    slug character varying(255),
    max_links_per_entity integer DEFAULT 10 NOT NULL,
    anonymized_at timestamp(0) without time zone,
    gender_consent boolean DEFAULT false NOT NULL,
    privacy_policy_accepted_at timestamp(0) without time zone,
    terms_accepted_at timestamp(0) without time zone,
    analytics_consent boolean DEFAULT false NOT NULL,
    weekly_digest_enabled boolean DEFAULT true NOT NULL
);


--
-- Name: customers id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers ALTER COLUMN id SET DEFAULT nextval('public.customers_id_seq'::regclass);


--
-- Name: escalated_agent_capacity id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_agent_capacity ALTER COLUMN id SET DEFAULT nextval('public.escalated_agent_capacity_id_seq'::regclass);


--
-- Name: escalated_agent_profiles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_agent_profiles ALTER COLUMN id SET DEFAULT nextval('public.escalated_agent_profiles_id_seq'::regclass);


--
-- Name: escalated_agent_skill id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_agent_skill ALTER COLUMN id SET DEFAULT nextval('public.escalated_agent_skill_id_seq'::regclass);


--
-- Name: escalated_api_tokens id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_api_tokens ALTER COLUMN id SET DEFAULT nextval('public.escalated_api_tokens_id_seq'::regclass);


--
-- Name: escalated_article_categories id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_article_categories ALTER COLUMN id SET DEFAULT nextval('public.escalated_article_categories_id_seq'::regclass);


--
-- Name: escalated_articles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_articles ALTER COLUMN id SET DEFAULT nextval('public.escalated_articles_id_seq'::regclass);


--
-- Name: escalated_attachments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_attachments ALTER COLUMN id SET DEFAULT nextval('public.escalated_attachments_id_seq'::regclass);


--
-- Name: escalated_audit_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_audit_logs ALTER COLUMN id SET DEFAULT nextval('public.escalated_audit_logs_id_seq'::regclass);


--
-- Name: escalated_automations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_automations ALTER COLUMN id SET DEFAULT nextval('public.escalated_automations_id_seq'::regclass);


--
-- Name: escalated_business_schedules id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_business_schedules ALTER COLUMN id SET DEFAULT nextval('public.escalated_business_schedules_id_seq'::regclass);


--
-- Name: escalated_canned_responses id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_canned_responses ALTER COLUMN id SET DEFAULT nextval('public.escalated_canned_responses_id_seq'::regclass);


--
-- Name: escalated_chat_routing_rules id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_chat_routing_rules ALTER COLUMN id SET DEFAULT nextval('public.escalated_chat_routing_rules_id_seq'::regclass);


--
-- Name: escalated_chat_sessions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_chat_sessions ALTER COLUMN id SET DEFAULT nextval('public.escalated_chat_sessions_id_seq'::regclass);


--
-- Name: escalated_contacts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_contacts ALTER COLUMN id SET DEFAULT nextval('public.escalated_contacts_id_seq'::regclass);


--
-- Name: escalated_custom_field_values id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_custom_field_values ALTER COLUMN id SET DEFAULT nextval('public.escalated_custom_field_values_id_seq'::regclass);


--
-- Name: escalated_custom_fields id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_custom_fields ALTER COLUMN id SET DEFAULT nextval('public.escalated_custom_fields_id_seq'::regclass);


--
-- Name: escalated_custom_object_records id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_custom_object_records ALTER COLUMN id SET DEFAULT nextval('public.escalated_custom_object_records_id_seq'::regclass);


--
-- Name: escalated_custom_objects id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_custom_objects ALTER COLUMN id SET DEFAULT nextval('public.escalated_custom_objects_id_seq'::regclass);


--
-- Name: escalated_delayed_actions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_delayed_actions ALTER COLUMN id SET DEFAULT nextval('public.escalated_delayed_actions_id_seq'::regclass);


--
-- Name: escalated_departments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_departments ALTER COLUMN id SET DEFAULT nextval('public.escalated_departments_id_seq'::regclass);


--
-- Name: escalated_escalation_rules id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_escalation_rules ALTER COLUMN id SET DEFAULT nextval('public.escalated_escalation_rules_id_seq'::regclass);


--
-- Name: escalated_holidays id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_holidays ALTER COLUMN id SET DEFAULT nextval('public.escalated_holidays_id_seq'::regclass);


--
-- Name: escalated_import_source_maps id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_import_source_maps ALTER COLUMN id SET DEFAULT nextval('public.escalated_import_source_maps_id_seq'::regclass);


--
-- Name: escalated_inbound_emails id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_inbound_emails ALTER COLUMN id SET DEFAULT nextval('public.escalated_inbound_emails_id_seq'::regclass);


--
-- Name: escalated_macros id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_macros ALTER COLUMN id SET DEFAULT nextval('public.escalated_macros_id_seq'::regclass);


--
-- Name: escalated_mentions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_mentions ALTER COLUMN id SET DEFAULT nextval('public.escalated_mentions_id_seq'::regclass);


--
-- Name: escalated_permissions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_permissions ALTER COLUMN id SET DEFAULT nextval('public.escalated_permissions_id_seq'::regclass);


--
-- Name: escalated_plugin_store id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_plugin_store ALTER COLUMN id SET DEFAULT nextval('public.escalated_plugin_store_id_seq'::regclass);


--
-- Name: escalated_plugins id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_plugins ALTER COLUMN id SET DEFAULT nextval('public.escalated_plugins_id_seq'::regclass);


--
-- Name: escalated_replies id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_replies ALTER COLUMN id SET DEFAULT nextval('public.escalated_replies_id_seq'::regclass);


--
-- Name: escalated_roles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_roles ALTER COLUMN id SET DEFAULT nextval('public.escalated_roles_id_seq'::regclass);


--
-- Name: escalated_satisfaction_ratings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_satisfaction_ratings ALTER COLUMN id SET DEFAULT nextval('public.escalated_satisfaction_ratings_id_seq'::regclass);


--
-- Name: escalated_saved_views id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_saved_views ALTER COLUMN id SET DEFAULT nextval('public.escalated_saved_views_id_seq'::regclass);


--
-- Name: escalated_settings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_settings ALTER COLUMN id SET DEFAULT nextval('public.escalated_settings_id_seq'::regclass);


--
-- Name: escalated_side_conversation_replies id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_side_conversation_replies ALTER COLUMN id SET DEFAULT nextval('public.escalated_side_conversation_replies_id_seq'::regclass);


--
-- Name: escalated_side_conversations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_side_conversations ALTER COLUMN id SET DEFAULT nextval('public.escalated_side_conversations_id_seq'::regclass);


--
-- Name: escalated_skills id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_skills ALTER COLUMN id SET DEFAULT nextval('public.escalated_skills_id_seq'::regclass);


--
-- Name: escalated_sla_policies id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_sla_policies ALTER COLUMN id SET DEFAULT nextval('public.escalated_sla_policies_id_seq'::regclass);


--
-- Name: escalated_tags id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_tags ALTER COLUMN id SET DEFAULT nextval('public.escalated_tags_id_seq'::regclass);


--
-- Name: escalated_ticket_activities id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_ticket_activities ALTER COLUMN id SET DEFAULT nextval('public.escalated_ticket_activities_id_seq'::regclass);


--
-- Name: escalated_ticket_links id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_ticket_links ALTER COLUMN id SET DEFAULT nextval('public.escalated_ticket_links_id_seq'::regclass);


--
-- Name: escalated_ticket_statuses id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_ticket_statuses ALTER COLUMN id SET DEFAULT nextval('public.escalated_ticket_statuses_id_seq'::regclass);


--
-- Name: escalated_ticket_subjects id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_ticket_subjects ALTER COLUMN id SET DEFAULT nextval('public.escalated_ticket_subjects_id_seq'::regclass);


--
-- Name: escalated_tickets id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_tickets ALTER COLUMN id SET DEFAULT nextval('public.escalated_tickets_id_seq'::regclass);


--
-- Name: escalated_two_factor id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_two_factor ALTER COLUMN id SET DEFAULT nextval('public.escalated_two_factor_id_seq'::regclass);


--
-- Name: escalated_webhook_deliveries id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_webhook_deliveries ALTER COLUMN id SET DEFAULT nextval('public.escalated_webhook_deliveries_id_seq'::regclass);


--
-- Name: escalated_webhooks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_webhooks ALTER COLUMN id SET DEFAULT nextval('public.escalated_webhooks_id_seq'::regclass);


--
-- Name: escalated_workflow_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_workflow_logs ALTER COLUMN id SET DEFAULT nextval('public.escalated_workflow_logs_id_seq'::regclass);


--
-- Name: escalated_workflows id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_workflows ALTER COLUMN id SET DEFAULT nextval('public.escalated_workflows_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: gm_social_links id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gm_social_links ALTER COLUMN id SET DEFAULT nextval('public.gm_social_links_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: permissions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions ALTER COLUMN id SET DEFAULT nextval('public.permissions_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('public.personal_access_tokens_id_seq'::regclass);


--
-- Name: roles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles ALTER COLUMN id SET DEFAULT nextval('public.roles_id_seq'::regclass);


--
-- Name: seo id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo ALTER COLUMN id SET DEFAULT nextval('public.seo_id_seq'::regclass);


--
-- Name: short_link_hits id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.short_link_hits ALTER COLUMN id SET DEFAULT nextval('public.short_link_hits_id_seq'::regclass);


--
-- Name: short_links id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.short_links ALTER COLUMN id SET DEFAULT nextval('public.short_links_id_seq'::regclass);


--
-- Name: subscription_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_items ALTER COLUMN id SET DEFAULT nextval('public.subscription_items_id_seq'::regclass);


--
-- Name: subscriptions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions ALTER COLUMN id SET DEFAULT nextval('public.subscriptions_id_seq'::regclass);


--
-- Name: suppressed_invite_emails id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.suppressed_invite_emails ALTER COLUMN id SET DEFAULT nextval('public.suppressed_invite_emails_id_seq'::regclass);


--
-- Name: transactions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transactions ALTER COLUMN id SET DEFAULT nextval('public.transactions_id_seq'::regclass);


--
-- Name: activity_logs activity_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.activity_logs
    ADD CONSTRAINT activity_logs_pkey PRIMARY KEY (id);


--
-- Name: attendance_reports attendance_reports_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attendance_reports
    ADD CONSTRAINT attendance_reports_pkey PRIMARY KEY (id);


--
-- Name: bgg_sync_logs bgg_sync_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bgg_sync_logs
    ADD CONSTRAINT bgg_sync_logs_pkey PRIMARY KEY (id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: campaign_applications campaign_applications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.campaign_applications
    ADD CONSTRAINT campaign_applications_pkey PRIMARY KEY (id);


--
-- Name: campaign_game_system campaign_game_system_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.campaign_game_system
    ADD CONSTRAINT campaign_game_system_pkey PRIMARY KEY (campaign_id, game_system_id);


--
-- Name: campaign_participants campaign_participants_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.campaign_participants
    ADD CONSTRAINT campaign_participants_pkey PRIMARY KEY (id);


--
-- Name: campaigns campaigns_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.campaigns
    ADD CONSTRAINT campaigns_pkey PRIMARY KEY (id);


--
-- Name: customers customers_paddle_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT customers_paddle_id_unique UNIQUE (paddle_id);


--
-- Name: customers customers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT customers_pkey PRIMARY KEY (id);


--
-- Name: escalated_agent_capacity escalated_agent_capacity_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_agent_capacity
    ADD CONSTRAINT escalated_agent_capacity_pkey PRIMARY KEY (id);


--
-- Name: escalated_agent_profiles escalated_agent_profiles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_agent_profiles
    ADD CONSTRAINT escalated_agent_profiles_pkey PRIMARY KEY (id);


--
-- Name: escalated_agent_profiles escalated_agent_profiles_user_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_agent_profiles
    ADD CONSTRAINT escalated_agent_profiles_user_id_unique UNIQUE (user_id);


--
-- Name: escalated_agent_skill escalated_agent_skill_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_agent_skill
    ADD CONSTRAINT escalated_agent_skill_pkey PRIMARY KEY (id);


--
-- Name: escalated_api_tokens escalated_api_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_api_tokens
    ADD CONSTRAINT escalated_api_tokens_pkey PRIMARY KEY (id);


--
-- Name: escalated_api_tokens escalated_api_tokens_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_api_tokens
    ADD CONSTRAINT escalated_api_tokens_token_unique UNIQUE (token);


--
-- Name: escalated_article_categories escalated_article_categories_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_article_categories
    ADD CONSTRAINT escalated_article_categories_pkey PRIMARY KEY (id);


--
-- Name: escalated_article_categories escalated_article_categories_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_article_categories
    ADD CONSTRAINT escalated_article_categories_slug_unique UNIQUE (slug);


--
-- Name: escalated_articles escalated_articles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_articles
    ADD CONSTRAINT escalated_articles_pkey PRIMARY KEY (id);


--
-- Name: escalated_articles escalated_articles_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_articles
    ADD CONSTRAINT escalated_articles_slug_unique UNIQUE (slug);


--
-- Name: escalated_attachments escalated_attachments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_attachments
    ADD CONSTRAINT escalated_attachments_pkey PRIMARY KEY (id);


--
-- Name: escalated_audit_logs escalated_audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_audit_logs
    ADD CONSTRAINT escalated_audit_logs_pkey PRIMARY KEY (id);


--
-- Name: escalated_automations escalated_automations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_automations
    ADD CONSTRAINT escalated_automations_pkey PRIMARY KEY (id);


--
-- Name: escalated_business_schedules escalated_business_schedules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_business_schedules
    ADD CONSTRAINT escalated_business_schedules_pkey PRIMARY KEY (id);


--
-- Name: escalated_canned_responses escalated_canned_responses_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_canned_responses
    ADD CONSTRAINT escalated_canned_responses_pkey PRIMARY KEY (id);


--
-- Name: escalated_chat_routing_rules escalated_chat_routing_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_chat_routing_rules
    ADD CONSTRAINT escalated_chat_routing_rules_pkey PRIMARY KEY (id);


--
-- Name: escalated_chat_sessions escalated_chat_sessions_customer_session_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_chat_sessions
    ADD CONSTRAINT escalated_chat_sessions_customer_session_id_unique UNIQUE (customer_session_id);


--
-- Name: escalated_chat_sessions escalated_chat_sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_chat_sessions
    ADD CONSTRAINT escalated_chat_sessions_pkey PRIMARY KEY (id);


--
-- Name: escalated_contacts escalated_contacts_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_contacts
    ADD CONSTRAINT escalated_contacts_email_unique UNIQUE (email);


--
-- Name: escalated_contacts escalated_contacts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_contacts
    ADD CONSTRAINT escalated_contacts_pkey PRIMARY KEY (id);


--
-- Name: escalated_custom_field_values escalated_custom_field_values_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_custom_field_values
    ADD CONSTRAINT escalated_custom_field_values_pkey PRIMARY KEY (id);


--
-- Name: escalated_custom_fields escalated_custom_fields_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_custom_fields
    ADD CONSTRAINT escalated_custom_fields_pkey PRIMARY KEY (id);


--
-- Name: escalated_custom_fields escalated_custom_fields_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_custom_fields
    ADD CONSTRAINT escalated_custom_fields_slug_unique UNIQUE (slug);


--
-- Name: escalated_custom_object_records escalated_custom_object_records_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_custom_object_records
    ADD CONSTRAINT escalated_custom_object_records_pkey PRIMARY KEY (id);


--
-- Name: escalated_custom_objects escalated_custom_objects_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_custom_objects
    ADD CONSTRAINT escalated_custom_objects_pkey PRIMARY KEY (id);


--
-- Name: escalated_custom_objects escalated_custom_objects_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_custom_objects
    ADD CONSTRAINT escalated_custom_objects_slug_unique UNIQUE (slug);


--
-- Name: escalated_delayed_actions escalated_delayed_actions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_delayed_actions
    ADD CONSTRAINT escalated_delayed_actions_pkey PRIMARY KEY (id);


--
-- Name: escalated_departments escalated_departments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_departments
    ADD CONSTRAINT escalated_departments_pkey PRIMARY KEY (id);


--
-- Name: escalated_departments escalated_departments_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_departments
    ADD CONSTRAINT escalated_departments_slug_unique UNIQUE (slug);


--
-- Name: escalated_escalation_rules escalated_escalation_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_escalation_rules
    ADD CONSTRAINT escalated_escalation_rules_pkey PRIMARY KEY (id);


--
-- Name: escalated_holidays escalated_holidays_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_holidays
    ADD CONSTRAINT escalated_holidays_pkey PRIMARY KEY (id);


--
-- Name: escalated_import_jobs escalated_import_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_import_jobs
    ADD CONSTRAINT escalated_import_jobs_pkey PRIMARY KEY (id);


--
-- Name: escalated_import_source_maps escalated_import_source_maps_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_import_source_maps
    ADD CONSTRAINT escalated_import_source_maps_pkey PRIMARY KEY (id);


--
-- Name: escalated_inbound_emails escalated_inbound_emails_message_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_inbound_emails
    ADD CONSTRAINT escalated_inbound_emails_message_id_unique UNIQUE (message_id);


--
-- Name: escalated_inbound_emails escalated_inbound_emails_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_inbound_emails
    ADD CONSTRAINT escalated_inbound_emails_pkey PRIMARY KEY (id);


--
-- Name: escalated_macros escalated_macros_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_macros
    ADD CONSTRAINT escalated_macros_pkey PRIMARY KEY (id);


--
-- Name: escalated_mentions escalated_mentions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_mentions
    ADD CONSTRAINT escalated_mentions_pkey PRIMARY KEY (id);


--
-- Name: escalated_permissions escalated_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_permissions
    ADD CONSTRAINT escalated_permissions_pkey PRIMARY KEY (id);


--
-- Name: escalated_permissions escalated_permissions_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_permissions
    ADD CONSTRAINT escalated_permissions_slug_unique UNIQUE (slug);


--
-- Name: escalated_plugin_store escalated_plugin_store_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_plugin_store
    ADD CONSTRAINT escalated_plugin_store_pkey PRIMARY KEY (id);


--
-- Name: escalated_plugins escalated_plugins_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_plugins
    ADD CONSTRAINT escalated_plugins_pkey PRIMARY KEY (id);


--
-- Name: escalated_plugins escalated_plugins_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_plugins
    ADD CONSTRAINT escalated_plugins_slug_unique UNIQUE (slug);


--
-- Name: escalated_replies escalated_replies_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_replies
    ADD CONSTRAINT escalated_replies_pkey PRIMARY KEY (id);


--
-- Name: escalated_role_permission escalated_role_permission_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_role_permission
    ADD CONSTRAINT escalated_role_permission_pkey PRIMARY KEY (role_id, permission_id);


--
-- Name: escalated_roles escalated_roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_roles
    ADD CONSTRAINT escalated_roles_pkey PRIMARY KEY (id);


--
-- Name: escalated_roles escalated_roles_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_roles
    ADD CONSTRAINT escalated_roles_slug_unique UNIQUE (slug);


--
-- Name: escalated_satisfaction_ratings escalated_satisfaction_ratings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_satisfaction_ratings
    ADD CONSTRAINT escalated_satisfaction_ratings_pkey PRIMARY KEY (id);


--
-- Name: escalated_satisfaction_ratings escalated_satisfaction_ratings_ticket_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_satisfaction_ratings
    ADD CONSTRAINT escalated_satisfaction_ratings_ticket_id_unique UNIQUE (ticket_id);


--
-- Name: escalated_saved_views escalated_saved_views_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_saved_views
    ADD CONSTRAINT escalated_saved_views_pkey PRIMARY KEY (id);


--
-- Name: escalated_settings escalated_settings_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_settings
    ADD CONSTRAINT escalated_settings_key_unique UNIQUE (key);


--
-- Name: escalated_settings escalated_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_settings
    ADD CONSTRAINT escalated_settings_pkey PRIMARY KEY (id);


--
-- Name: escalated_side_conversation_replies escalated_side_conversation_replies_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_side_conversation_replies
    ADD CONSTRAINT escalated_side_conversation_replies_pkey PRIMARY KEY (id);


--
-- Name: escalated_side_conversations escalated_side_conversations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_side_conversations
    ADD CONSTRAINT escalated_side_conversations_pkey PRIMARY KEY (id);


--
-- Name: escalated_skills escalated_skills_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_skills
    ADD CONSTRAINT escalated_skills_pkey PRIMARY KEY (id);


--
-- Name: escalated_skills escalated_skills_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_skills
    ADD CONSTRAINT escalated_skills_slug_unique UNIQUE (slug);


--
-- Name: escalated_sla_policies escalated_sla_policies_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_sla_policies
    ADD CONSTRAINT escalated_sla_policies_pkey PRIMARY KEY (id);


--
-- Name: escalated_tags escalated_tags_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_tags
    ADD CONSTRAINT escalated_tags_pkey PRIMARY KEY (id);


--
-- Name: escalated_tags escalated_tags_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_tags
    ADD CONSTRAINT escalated_tags_slug_unique UNIQUE (slug);


--
-- Name: escalated_ticket_activities escalated_ticket_activities_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_ticket_activities
    ADD CONSTRAINT escalated_ticket_activities_pkey PRIMARY KEY (id);


--
-- Name: escalated_ticket_links escalated_ticket_links_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_ticket_links
    ADD CONSTRAINT escalated_ticket_links_pkey PRIMARY KEY (id);


--
-- Name: escalated_ticket_statuses escalated_ticket_statuses_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_ticket_statuses
    ADD CONSTRAINT escalated_ticket_statuses_pkey PRIMARY KEY (id);


--
-- Name: escalated_ticket_statuses escalated_ticket_statuses_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_ticket_statuses
    ADD CONSTRAINT escalated_ticket_statuses_slug_unique UNIQUE (slug);


--
-- Name: escalated_ticket_subjects escalated_ticket_subject_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_ticket_subjects
    ADD CONSTRAINT escalated_ticket_subject_unique UNIQUE (ticket_id, subject_type, subject_id);


--
-- Name: escalated_ticket_subjects escalated_ticket_subjects_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_ticket_subjects
    ADD CONSTRAINT escalated_ticket_subjects_pkey PRIMARY KEY (id);


--
-- Name: escalated_ticket_tag escalated_ticket_tag_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_ticket_tag
    ADD CONSTRAINT escalated_ticket_tag_pkey PRIMARY KEY (ticket_id, tag_id);


--
-- Name: escalated_tickets escalated_tickets_guest_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_tickets
    ADD CONSTRAINT escalated_tickets_guest_token_unique UNIQUE (guest_token);


--
-- Name: escalated_tickets escalated_tickets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_tickets
    ADD CONSTRAINT escalated_tickets_pkey PRIMARY KEY (id);


--
-- Name: escalated_tickets escalated_tickets_reference_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_tickets
    ADD CONSTRAINT escalated_tickets_reference_unique UNIQUE (reference);


--
-- Name: escalated_two_factor escalated_two_factor_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_two_factor
    ADD CONSTRAINT escalated_two_factor_pkey PRIMARY KEY (id);


--
-- Name: escalated_webhook_deliveries escalated_webhook_deliveries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_webhook_deliveries
    ADD CONSTRAINT escalated_webhook_deliveries_pkey PRIMARY KEY (id);


--
-- Name: escalated_webhooks escalated_webhooks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_webhooks
    ADD CONSTRAINT escalated_webhooks_pkey PRIMARY KEY (id);


--
-- Name: escalated_workflow_logs escalated_workflow_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_workflow_logs
    ADD CONSTRAINT escalated_workflow_logs_pkey PRIMARY KEY (id);


--
-- Name: escalated_workflows escalated_workflows_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_workflows
    ADD CONSTRAINT escalated_workflows_pkey PRIMARY KEY (id);


--
-- Name: event_announcements event_announcements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_announcements
    ADD CONSTRAINT event_announcements_pkey PRIMARY KEY (id);


--
-- Name: event_registrations event_registrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_registrations
    ADD CONSTRAINT event_registrations_pkey PRIMARY KEY (id);


--
-- Name: events events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.events
    ADD CONSTRAINT events_pkey PRIMARY KEY (id);


--
-- Name: events events_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.events
    ADD CONSTRAINT events_slug_unique UNIQUE (slug);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: game_applications game_applications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_applications
    ADD CONSTRAINT game_applications_pkey PRIMARY KEY (id);


--
-- Name: game_bulletins game_bulletins_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_bulletins
    ADD CONSTRAINT game_bulletins_pkey PRIMARY KEY (id);


--
-- Name: game_game_system game_game_system_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_game_system
    ADD CONSTRAINT game_game_system_pkey PRIMARY KEY (game_id, game_system_id);


--
-- Name: game_participants game_participants_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_participants
    ADD CONSTRAINT game_participants_pkey PRIMARY KEY (id);


--
-- Name: game_system_categories game_system_categories_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_categories
    ADD CONSTRAINT game_system_categories_pkey PRIMARY KEY (id);


--
-- Name: game_system_categories game_system_categories_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_categories
    ADD CONSTRAINT game_system_categories_slug_unique UNIQUE (slug);


--
-- Name: game_system_category game_system_category_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_category
    ADD CONSTRAINT game_system_category_pkey PRIMARY KEY (game_system_id, game_system_category_id);


--
-- Name: game_system_designer game_system_designer_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_designer
    ADD CONSTRAINT game_system_designer_pkey PRIMARY KEY (game_system_id, game_system_designer_id);


--
-- Name: game_system_designers game_system_designers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_designers
    ADD CONSTRAINT game_system_designers_pkey PRIMARY KEY (id);


--
-- Name: game_system_designers game_system_designers_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_designers
    ADD CONSTRAINT game_system_designers_slug_unique UNIQUE (slug);


--
-- Name: game_system_families game_system_families_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_families
    ADD CONSTRAINT game_system_families_pkey PRIMARY KEY (id);


--
-- Name: game_system_families game_system_families_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_families
    ADD CONSTRAINT game_system_families_slug_unique UNIQUE (slug);


--
-- Name: game_system_family game_system_family_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_family
    ADD CONSTRAINT game_system_family_pkey PRIMARY KEY (game_system_id, game_system_family_id);


--
-- Name: game_system_mechanic game_system_mechanic_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_mechanic
    ADD CONSTRAINT game_system_mechanic_pkey PRIMARY KEY (game_system_id, game_system_mechanic_id);


--
-- Name: game_system_mechanics game_system_mechanics_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_mechanics
    ADD CONSTRAINT game_system_mechanics_pkey PRIMARY KEY (id);


--
-- Name: game_system_mechanics game_system_mechanics_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_mechanics
    ADD CONSTRAINT game_system_mechanics_slug_unique UNIQUE (slug);


--
-- Name: game_system_publisher game_system_publisher_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_publisher
    ADD CONSTRAINT game_system_publisher_pkey PRIMARY KEY (game_system_id, game_system_publisher_id);


--
-- Name: game_system_publishers game_system_publishers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_publishers
    ADD CONSTRAINT game_system_publishers_pkey PRIMARY KEY (id);


--
-- Name: game_system_publishers game_system_publishers_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_publishers
    ADD CONSTRAINT game_system_publishers_slug_unique UNIQUE (slug);


--
-- Name: game_systems game_systems_bgg_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_systems
    ADD CONSTRAINT game_systems_bgg_id_unique UNIQUE (bgg_id);


--
-- Name: game_systems game_systems_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_systems
    ADD CONSTRAINT game_systems_pkey PRIMARY KEY (id);


--
-- Name: game_systems game_systems_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_systems
    ADD CONSTRAINT game_systems_slug_unique UNIQUE (slug);


--
-- Name: games games_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.games
    ADD CONSTRAINT games_pkey PRIMARY KEY (id);


--
-- Name: gm_profiles gm_profiles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gm_profiles
    ADD CONSTRAINT gm_profiles_pkey PRIMARY KEY (id);


--
-- Name: gm_profiles gm_profiles_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gm_profiles
    ADD CONSTRAINT gm_profiles_slug_unique UNIQUE (slug);


--
-- Name: gm_social_links gm_social_links_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gm_social_links
    ADD CONSTRAINT gm_social_links_pkey PRIMARY KEY (id);


--
-- Name: gm_social_links gm_social_links_user_id_platform_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gm_social_links
    ADD CONSTRAINT gm_social_links_user_id_platform_unique UNIQUE (user_id, platform);


--
-- Name: escalated_import_source_maps import_source_map_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_import_source_maps
    ADD CONSTRAINT import_source_map_unique UNIQUE (import_job_id, entity_type, source_id);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: linked_accounts linked_accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.linked_accounts
    ADD CONSTRAINT linked_accounts_pkey PRIMARY KEY (id);


--
-- Name: linked_accounts linked_accounts_provider_provider_user_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.linked_accounts
    ADD CONSTRAINT linked_accounts_provider_provider_user_id_unique UNIQUE (provider, provider_user_id);


--
-- Name: local_subscriptions local_subscriptions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.local_subscriptions
    ADD CONSTRAINT local_subscriptions_pkey PRIMARY KEY (id);


--
-- Name: locations locations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.locations
    ADD CONSTRAINT locations_pkey PRIMARY KEY (id);


--
-- Name: locations locations_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.locations
    ADD CONSTRAINT locations_slug_unique UNIQUE (slug);


--
-- Name: media media_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media
    ADD CONSTRAINT media_pkey PRIMARY KEY (id);


--
-- Name: media media_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media
    ADD CONSTRAINT media_uuid_unique UNIQUE (uuid);


--
-- Name: membership_types membership_types_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.membership_types
    ADD CONSTRAINT membership_types_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: model_has_permissions model_has_permissions_team_perm_model_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.model_has_permissions
    ADD CONSTRAINT model_has_permissions_team_perm_model_unique UNIQUE (team_id, permission_id, model_id, model_type);


--
-- Name: model_has_roles model_has_roles_model_id_model_type_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.model_has_roles
    ADD CONSTRAINT model_has_roles_model_id_model_type_unique UNIQUE (model_id, model_type, team_id);


--
-- Name: nearby_discovery_views nearby_discovery_views_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nearby_discovery_views
    ADD CONSTRAINT nearby_discovery_views_pkey PRIMARY KEY (id);


--
-- Name: notifications notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_pkey PRIMARY KEY (id);


--
-- Name: escalated_ticket_links parent_child_type_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_ticket_links
    ADD CONSTRAINT parent_child_type_unique UNIQUE (parent_ticket_id, child_ticket_id, link_type);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: permissions permissions_name_guard_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_name_guard_name_unique UNIQUE (name, guard_name);


--
-- Name: permissions permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_unique UNIQUE (token);


--
-- Name: push_subscriptions push_subscriptions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.push_subscriptions
    ADD CONSTRAINT push_subscriptions_pkey PRIMARY KEY (id);


--
-- Name: reviews reviews_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reviews
    ADD CONSTRAINT reviews_pkey PRIMARY KEY (id);


--
-- Name: role_has_permissions role_has_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_has_permissions
    ADD CONSTRAINT role_has_permissions_pkey PRIMARY KEY (permission_id, role_id);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: roles roles_team_id_name_guard_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_team_id_name_guard_name_unique UNIQUE (team_id, name, guard_name);


--
-- Name: seo seo_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo
    ADD CONSTRAINT seo_pkey PRIMARY KEY (id);


--
-- Name: session_debriefings session_debriefings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.session_debriefings
    ADD CONSTRAINT session_debriefings_pkey PRIMARY KEY (id);


--
-- Name: session_zero_confirmations session_zero_confirmations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.session_zero_confirmations
    ADD CONSTRAINT session_zero_confirmations_pkey PRIMARY KEY (id);


--
-- Name: session_zero_surveys session_zero_surveys_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.session_zero_surveys
    ADD CONSTRAINT session_zero_surveys_pkey PRIMARY KEY (id);


--
-- Name: session_zero_surveys session_zero_surveys_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.session_zero_surveys
    ADD CONSTRAINT session_zero_surveys_uuid_unique UNIQUE (uuid);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: short_link_hits short_link_hits_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.short_link_hits
    ADD CONSTRAINT short_link_hits_pkey PRIMARY KEY (id);


--
-- Name: short_links short_links_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.short_links
    ADD CONSTRAINT short_links_code_unique UNIQUE (code);


--
-- Name: short_links short_links_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.short_links
    ADD CONSTRAINT short_links_pkey PRIMARY KEY (id);


--
-- Name: subscription_items subscription_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_items
    ADD CONSTRAINT subscription_items_pkey PRIMARY KEY (id);


--
-- Name: subscription_items subscription_items_subscription_id_price_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_items
    ADD CONSTRAINT subscription_items_subscription_id_price_id_unique UNIQUE (subscription_id, price_id);


--
-- Name: subscriptions subscriptions_paddle_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT subscriptions_paddle_id_unique UNIQUE (paddle_id);


--
-- Name: subscriptions subscriptions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT subscriptions_pkey PRIMARY KEY (id);


--
-- Name: suppressed_invite_emails suppressed_invite_emails_email_hash_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.suppressed_invite_emails
    ADD CONSTRAINT suppressed_invite_emails_email_hash_unique UNIQUE (email_hash);


--
-- Name: suppressed_invite_emails suppressed_invite_emails_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.suppressed_invite_emails
    ADD CONSTRAINT suppressed_invite_emails_pkey PRIMARY KEY (id);


--
-- Name: team_members team_members_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.team_members
    ADD CONSTRAINT team_members_pkey PRIMARY KEY (id);


--
-- Name: teams teams_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teams
    ADD CONSTRAINT teams_pkey PRIMARY KEY (id);


--
-- Name: teams teams_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teams
    ADD CONSTRAINT teams_slug_unique UNIQUE (slug);


--
-- Name: transactions transactions_paddle_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transactions
    ADD CONSTRAINT transactions_paddle_id_unique UNIQUE (paddle_id);


--
-- Name: transactions transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transactions
    ADD CONSTRAINT transactions_pkey PRIMARY KEY (id);


--
-- Name: user_app_visits user_app_visits_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_app_visits
    ADD CONSTRAINT user_app_visits_pkey PRIMARY KEY (id);


--
-- Name: user_game_system_preferences user_game_system_preferences_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_game_system_preferences
    ADD CONSTRAINT user_game_system_preferences_pkey PRIMARY KEY (user_id, game_system_id);


--
-- Name: user_relationships user_relationships_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_relationships
    ADD CONSTRAINT user_relationships_pkey PRIMARY KEY (id);


--
-- Name: user_vibe_preferences user_vibe_preferences_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_vibe_preferences
    ADD CONSTRAINT user_vibe_preferences_pkey PRIMARY KEY (user_id, vibe_preference_value);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_paddle_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_paddle_id_unique UNIQUE (paddle_id);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: users users_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_slug_unique UNIQUE (slug);


--
-- Name: activity_logs_user_event_type_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX activity_logs_user_event_type_idx ON public.activity_logs USING btree (user_id, event_type);


--
-- Name: activity_logs_user_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX activity_logs_user_id_created_at_index ON public.activity_logs USING btree (user_id, created_at);


--
-- Name: cache_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);


--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);


--
-- Name: campaign_applications_campaign_id_user_id_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX campaign_applications_campaign_id_user_id_unique ON public.campaign_applications USING btree (campaign_id, user_id);


--
-- Name: campaign_game_system_game_system_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX campaign_game_system_game_system_id_index ON public.campaign_game_system USING btree (game_system_id);


--
-- Name: campaign_participants_campaign_id_user_id_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX campaign_participants_campaign_id_user_id_unique ON public.campaign_participants USING btree (campaign_id, user_id);


--
-- Name: campaign_participants_invite_email_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX campaign_participants_invite_email_unique ON public.campaign_participants USING btree (campaign_id, invitee_email) WHERE (invitee_email IS NOT NULL);


--
-- Name: campaign_participants_short_link_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX campaign_participants_short_link_id_index ON public.campaign_participants USING btree (short_link_id);


--
-- Name: campaigns_share_token_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX campaigns_share_token_index ON public.campaigns USING btree (share_token);


--
-- Name: campaigns_visibility_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX campaigns_visibility_index ON public.campaigns USING btree (visibility);


--
-- Name: delayed_actions_exec_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX delayed_actions_exec_idx ON public.escalated_delayed_actions USING btree (execute_at, executed, cancelled);


--
-- Name: escalated_api_tokens_tokenable_type_tokenable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_api_tokens_tokenable_type_tokenable_id_index ON public.escalated_api_tokens USING btree (tokenable_type, tokenable_id);


--
-- Name: escalated_attachments_attachable_type_attachable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_attachments_attachable_type_attachable_id_index ON public.escalated_attachments USING btree (attachable_type, attachable_id);


--
-- Name: escalated_audit_logs_action_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_audit_logs_action_index ON public.escalated_audit_logs USING btree (action);


--
-- Name: escalated_audit_logs_auditable_type_auditable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_audit_logs_auditable_type_auditable_id_index ON public.escalated_audit_logs USING btree (auditable_type, auditable_id);


--
-- Name: escalated_audit_logs_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_audit_logs_created_at_index ON public.escalated_audit_logs USING btree (created_at);


--
-- Name: escalated_automations_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_automations_active_index ON public.escalated_automations USING btree (active);


--
-- Name: escalated_chat_routing_rules_department_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_chat_routing_rules_department_id_index ON public.escalated_chat_routing_rules USING btree (department_id);


--
-- Name: escalated_chat_routing_rules_position_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_chat_routing_rules_position_index ON public.escalated_chat_routing_rules USING btree ("position");


--
-- Name: escalated_chat_sessions_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_chat_sessions_status_index ON public.escalated_chat_sessions USING btree (status);


--
-- Name: escalated_chat_sessions_ticket_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_chat_sessions_ticket_id_index ON public.escalated_chat_sessions USING btree (ticket_id);


--
-- Name: escalated_custom_field_values_entity_type_entity_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_custom_field_values_entity_type_entity_id_index ON public.escalated_custom_field_values USING btree (entity_type, entity_id);


--
-- Name: escalated_custom_object_records_object_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_custom_object_records_object_id_index ON public.escalated_custom_object_records USING btree (object_id);


--
-- Name: escalated_delayed_actions_ticket_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_delayed_actions_ticket_id_index ON public.escalated_delayed_actions USING btree (ticket_id);


--
-- Name: escalated_import_jobs_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_import_jobs_status_index ON public.escalated_import_jobs USING btree (status);


--
-- Name: escalated_import_source_maps_import_job_id_entity_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_import_source_maps_import_job_id_entity_type_index ON public.escalated_import_source_maps USING btree (import_job_id, entity_type);


--
-- Name: escalated_inbound_emails_adapter_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_inbound_emails_adapter_index ON public.escalated_inbound_emails USING btree (adapter);


--
-- Name: escalated_inbound_emails_from_email_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_inbound_emails_from_email_index ON public.escalated_inbound_emails USING btree (from_email);


--
-- Name: escalated_inbound_emails_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_inbound_emails_status_index ON public.escalated_inbound_emails USING btree (status);


--
-- Name: escalated_plugin_store_plugin_collection_key_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_plugin_store_plugin_collection_key_index ON public.escalated_plugin_store USING btree (plugin, collection, key);


--
-- Name: escalated_plugins_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_plugins_is_active_index ON public.escalated_plugins USING btree (is_active);


--
-- Name: escalated_plugins_slug_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_plugins_slug_index ON public.escalated_plugins USING btree (slug);


--
-- Name: escalated_replies_author_type_author_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_replies_author_type_author_id_index ON public.escalated_replies USING btree (author_type, author_id);


--
-- Name: escalated_satisfaction_ratings_rated_by_type_rated_by_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_satisfaction_ratings_rated_by_type_rated_by_id_index ON public.escalated_satisfaction_ratings USING btree (rated_by_type, rated_by_id);


--
-- Name: escalated_side_conversation_replies_side_conversation_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_side_conversation_replies_side_conversation_id_index ON public.escalated_side_conversation_replies USING btree (side_conversation_id);


--
-- Name: escalated_side_conversations_ticket_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_side_conversations_ticket_id_index ON public.escalated_side_conversations USING btree (ticket_id);


--
-- Name: escalated_ticket_activities_causer_type_causer_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_ticket_activities_causer_type_causer_id_index ON public.escalated_ticket_activities USING btree (causer_type, causer_id);


--
-- Name: escalated_ticket_subjects_subject_type_subject_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_ticket_subjects_subject_type_subject_id_index ON public.escalated_ticket_subjects USING btree (subject_type, subject_id);


--
-- Name: escalated_tickets_assigned_to_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_tickets_assigned_to_index ON public.escalated_tickets USING btree (assigned_to);


--
-- Name: escalated_tickets_contact_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_tickets_contact_id_index ON public.escalated_tickets USING btree (contact_id);


--
-- Name: escalated_tickets_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_tickets_created_at_index ON public.escalated_tickets USING btree (created_at);


--
-- Name: escalated_tickets_department_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_tickets_department_id_index ON public.escalated_tickets USING btree (department_id);


--
-- Name: escalated_tickets_first_response_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_tickets_first_response_at_index ON public.escalated_tickets USING btree (first_response_at);


--
-- Name: escalated_tickets_first_response_due_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_tickets_first_response_due_at_index ON public.escalated_tickets USING btree (first_response_due_at);


--
-- Name: escalated_tickets_priority_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_tickets_priority_index ON public.escalated_tickets USING btree (priority);


--
-- Name: escalated_tickets_requester_type_requester_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_tickets_requester_type_requester_id_index ON public.escalated_tickets USING btree (requester_type, requester_id);


--
-- Name: escalated_tickets_resolution_due_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_tickets_resolution_due_at_index ON public.escalated_tickets USING btree (resolution_due_at);


--
-- Name: escalated_tickets_resolved_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_tickets_resolved_at_index ON public.escalated_tickets USING btree (resolved_at);


--
-- Name: escalated_tickets_sla_fr_breach_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_tickets_sla_fr_breach_idx ON public.escalated_tickets USING btree (sla_first_response_breached, first_response_due_at);


--
-- Name: escalated_tickets_sla_res_breach_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_tickets_sla_res_breach_idx ON public.escalated_tickets USING btree (sla_resolution_breached, resolution_due_at);


--
-- Name: escalated_tickets_snoozed_until_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_tickets_snoozed_until_index ON public.escalated_tickets USING btree (snoozed_until);


--
-- Name: escalated_tickets_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_tickets_status_index ON public.escalated_tickets USING btree (status);


--
-- Name: escalated_tickets_ticket_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_tickets_ticket_type_index ON public.escalated_tickets USING btree (ticket_type);


--
-- Name: escalated_webhook_deliveries_event_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_webhook_deliveries_event_index ON public.escalated_webhook_deliveries USING btree (event);


--
-- Name: escalated_webhook_deliveries_webhook_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_webhook_deliveries_webhook_id_index ON public.escalated_webhook_deliveries USING btree (webhook_id);


--
-- Name: escalated_workflow_logs_ticket_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_workflow_logs_ticket_id_index ON public.escalated_workflow_logs USING btree (ticket_id);


--
-- Name: escalated_workflow_logs_workflow_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_workflow_logs_workflow_id_index ON public.escalated_workflow_logs USING btree (workflow_id);


--
-- Name: escalated_workflows_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_workflows_is_active_index ON public.escalated_workflows USING btree (is_active);


--
-- Name: escalated_workflows_position_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_workflows_position_index ON public.escalated_workflows USING btree ("position");


--
-- Name: escalated_workflows_trigger_event_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX escalated_workflows_trigger_event_index ON public.escalated_workflows USING btree (trigger_event);


--
-- Name: event_announcements_event_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX event_announcements_event_index ON public.event_announcements USING btree (event_id);


--
-- Name: event_registrations_event_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX event_registrations_event_index ON public.event_registrations USING btree (event_id);


--
-- Name: event_registrations_user_event_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX event_registrations_user_event_index ON public.event_registrations USING btree (user_id, event_id);


--
-- Name: events_featured_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX events_featured_index ON public.events USING btree (is_featured);


--
-- Name: events_listing_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX events_listing_index ON public.events USING btree (is_public, status, start_date);


--
-- Name: game_applications_game_id_user_id_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX game_applications_game_id_user_id_unique ON public.game_applications USING btree (game_id, user_id);


--
-- Name: game_bulletins_game_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX game_bulletins_game_id_created_at_index ON public.game_bulletins USING btree (game_id, created_at);


--
-- Name: game_bulletins_game_id_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX game_bulletins_game_id_expires_at_index ON public.game_bulletins USING btree (game_id, expires_at);


--
-- Name: game_game_system_game_system_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX game_game_system_game_system_id_index ON public.game_game_system USING btree (game_system_id);


--
-- Name: game_participants_game_id_user_id_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX game_participants_game_id_user_id_unique ON public.game_participants USING btree (game_id, user_id);


--
-- Name: game_participants_invite_email_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX game_participants_invite_email_unique ON public.game_participants USING btree (game_id, invitee_email) WHERE (invitee_email IS NOT NULL);


--
-- Name: game_participants_short_link_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX game_participants_short_link_id_index ON public.game_participants USING btree (short_link_id);


--
-- Name: game_systems_platform_score_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX game_systems_platform_score_index ON public.game_systems USING btree (platform_score);


--
-- Name: game_systems_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX game_systems_type_index ON public.game_systems USING btree (type);


--
-- Name: games_share_token_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX games_share_token_index ON public.games USING btree (share_token);


--
-- Name: games_unresolved_window_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX games_unresolved_window_idx ON public.games USING btree (attendance_window_closes_at, attendance_resolved_at);


--
-- Name: games_visibility_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX games_visibility_index ON public.games USING btree (visibility);


--
-- Name: gm_profiles_user_id_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX gm_profiles_user_id_unique ON public.gm_profiles USING btree (user_id);


--
-- Name: gm_social_links_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX gm_social_links_user_id_index ON public.gm_social_links USING btree (user_id);


--
-- Name: idx_campaigns_description_en_trgm; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_campaigns_description_en_trgm ON public.campaigns USING gin (((description ->> 'en'::text)) public.gin_trgm_ops);


--
-- Name: idx_campaigns_name_en_trgm; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_campaigns_name_en_trgm ON public.campaigns USING gin (((name ->> 'en'::text)) public.gin_trgm_ops);


--
-- Name: idx_event_announcements_content_en_trgm; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_event_announcements_content_en_trgm ON public.event_announcements USING gin (((content ->> 'en'::text)) public.gin_trgm_ops);


--
-- Name: idx_event_announcements_title_en_trgm; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_event_announcements_title_en_trgm ON public.event_announcements USING gin (((title ->> 'en'::text)) public.gin_trgm_ops);


--
-- Name: idx_events_description_en_trgm; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_events_description_en_trgm ON public.events USING gin (((description ->> 'en'::text)) public.gin_trgm_ops);


--
-- Name: idx_events_name_en_trgm; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_events_name_en_trgm ON public.events USING gin (((name ->> 'en'::text)) public.gin_trgm_ops);


--
-- Name: idx_game_systems_description_en_trgm; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_game_systems_description_en_trgm ON public.game_systems USING gin (((description ->> 'en'::text)) public.gin_trgm_ops);


--
-- Name: idx_game_systems_name_de_trgm; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_game_systems_name_de_trgm ON public.game_systems USING gin (((name ->> 'de'::text)) public.gin_trgm_ops);


--
-- Name: idx_game_systems_name_en_trgm; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_game_systems_name_en_trgm ON public.game_systems USING gin (((name ->> 'en'::text)) public.gin_trgm_ops);


--
-- Name: idx_games_description_en_trgm; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_games_description_en_trgm ON public.games USING gin (((description ->> 'en'::text)) public.gin_trgm_ops);


--
-- Name: idx_games_name_en_trgm; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_games_name_en_trgm ON public.games USING gin (((name ->> 'en'::text)) public.gin_trgm_ops);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: linked_accounts_user_id_provider_index; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX linked_accounts_user_id_provider_index ON public.linked_accounts USING btree (user_id, provider);


--
-- Name: local_subscriptions_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX local_subscriptions_status_index ON public.local_subscriptions USING btree (status);


--
-- Name: locations_drift_detected_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX locations_drift_detected_at_index ON public.locations USING btree (drift_detected_at);


--
-- Name: locations_drift_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX locations_drift_status_index ON public.locations USING btree (drift_status);


--
-- Name: locations_geohash_4_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX locations_geohash_4_index ON public.locations USING btree (geohash_4);


--
-- Name: locations_is_verified_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX locations_is_verified_index ON public.locations USING btree (is_verified);


--
-- Name: locations_lat_lng_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX locations_lat_lng_index ON public.locations USING btree (latitude, longitude);


--
-- Name: locations_place_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX locations_place_id_index ON public.locations USING btree (place_id);


--
-- Name: media_model_type_model_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX media_model_type_model_id_index ON public.media USING btree (model_type, model_id);


--
-- Name: media_order_column_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX media_order_column_index ON public.media USING btree (order_column);


--
-- Name: model_has_permissions_team_foreign_key_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX model_has_permissions_team_foreign_key_index ON public.model_has_permissions USING btree (team_id);


--
-- Name: model_has_roles_team_foreign_key_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX model_has_roles_team_foreign_key_index ON public.model_has_roles USING btree (team_id);


--
-- Name: nearby_discovery_views_user_id_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX nearby_discovery_views_user_id_unique ON public.nearby_discovery_views USING btree (user_id);


--
-- Name: personal_access_tokens_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_expires_at_index ON public.personal_access_tokens USING btree (expires_at);


--
-- Name: personal_access_tokens_tokenable_type_tokenable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON public.personal_access_tokens USING btree (tokenable_type, tokenable_id);


--
-- Name: push_subscriptions_endpoint_user_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX push_subscriptions_endpoint_user_unique ON public.push_subscriptions USING btree (endpoint, user_id);


--
-- Name: push_subscriptions_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX push_subscriptions_user_id_index ON public.push_subscriptions USING btree (user_id);


--
-- Name: reviews_gm_profile_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX reviews_gm_profile_id_index ON public.reviews USING btree (gm_profile_id);


--
-- Name: reviews_reviewable_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX reviews_reviewable_unique ON public.reviews USING btree (reviewable_type, reviewable_id, reviewer_id);


--
-- Name: roles_team_foreign_key_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX roles_team_foreign_key_index ON public.roles USING btree (team_id);


--
-- Name: seo_model_type_model_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_model_type_model_id_index ON public.seo USING btree (model_type, model_id);


--
-- Name: session_debriefings_game_id_user_id_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX session_debriefings_game_id_user_id_unique ON public.session_debriefings USING btree (game_id, user_id);


--
-- Name: session_debriefings_submitted_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX session_debriefings_submitted_at_index ON public.session_debriefings USING btree (submitted_at);


--
-- Name: session_debriefings_tool_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX session_debriefings_tool_type_index ON public.session_debriefings USING btree (tool_type);


--
-- Name: session_zero_confirmations_session_zero_survey_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX session_zero_confirmations_session_zero_survey_id_index ON public.session_zero_confirmations USING btree (session_zero_survey_id);


--
-- Name: session_zero_confirmations_session_zero_survey_id_user_id_uniqu; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX session_zero_confirmations_session_zero_survey_id_user_id_uniqu ON public.session_zero_confirmations USING btree (session_zero_survey_id, user_id);


--
-- Name: session_zero_confirmations_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX session_zero_confirmations_user_id_index ON public.session_zero_confirmations USING btree (user_id);


--
-- Name: session_zero_surveys_game_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX session_zero_surveys_game_id_index ON public.session_zero_surveys USING btree (game_id);


--
-- Name: session_zero_surveys_gm_profile_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX session_zero_surveys_gm_profile_id_index ON public.session_zero_surveys USING btree (gm_profile_id);


--
-- Name: session_zero_surveys_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX session_zero_surveys_status_index ON public.session_zero_surveys USING btree (status);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: short_link_hits_hit_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX short_link_hits_hit_at_index ON public.short_link_hits USING btree (hit_at);


--
-- Name: short_link_hits_referer_domain_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX short_link_hits_referer_domain_index ON public.short_link_hits USING btree (referer_domain);


--
-- Name: short_link_hits_short_link_id_hit_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX short_link_hits_short_link_id_hit_at_index ON public.short_link_hits USING btree (short_link_id, hit_at);


--
-- Name: short_links_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX short_links_expires_at_index ON public.short_links USING btree (expires_at);


--
-- Name: short_links_linkable_type_linkable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX short_links_linkable_type_linkable_id_index ON public.short_links USING btree (linkable_type, linkable_id);


--
-- Name: short_links_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX short_links_user_id_index ON public.short_links USING btree (user_id);


--
-- Name: team_members_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX team_members_user_id_index ON public.team_members USING btree (user_id);


--
-- Name: team_members_user_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX team_members_user_status_index ON public.team_members USING btree (user_id, status);


--
-- Name: transactions_paddle_subscription_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX transactions_paddle_subscription_id_index ON public.transactions USING btree (paddle_subscription_id);


--
-- Name: user_app_visits_user_id_visit_date_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX user_app_visits_user_id_visit_date_unique ON public.user_app_visits USING btree (user_id, visit_date);


--
-- Name: user_app_visits_visit_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_app_visits_visit_date_index ON public.user_app_visits USING btree (visit_date);


--
-- Name: user_relationships_related_user_id_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_relationships_related_user_id_type_index ON public.user_relationships USING btree (related_user_id, type);


--
-- Name: user_relationships_user_id_related_user_id_type_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX user_relationships_user_id_related_user_id_type_unique ON public.user_relationships USING btree (user_id, related_user_id, type);


--
-- Name: user_relationships_user_id_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_relationships_user_id_type_index ON public.user_relationships USING btree (user_id, type);


--
-- Name: user_vibe_preferences_user_id_preference_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_vibe_preferences_user_id_preference_type_index ON public.user_vibe_preferences USING btree (user_id, preference_type);


--
-- Name: users_anonymized_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_anonymized_at_index ON public.users USING btree (anonymized_at);


--
-- Name: locations locations_geohash_4_trigger; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER locations_geohash_4_trigger BEFORE INSERT OR UPDATE OF latitude, longitude ON public.locations FOR EACH ROW EXECUTE FUNCTION public.locations_compute_geohash_4();


--
-- Name: activity_logs activity_logs_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.activity_logs
    ADD CONSTRAINT activity_logs_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: attendance_reports attendance_reports_game_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attendance_reports
    ADD CONSTRAINT attendance_reports_game_id_foreign FOREIGN KEY (game_id) REFERENCES public.games(id) ON DELETE CASCADE;


--
-- Name: attendance_reports attendance_reports_reported_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attendance_reports
    ADD CONSTRAINT attendance_reports_reported_id_foreign FOREIGN KEY (reported_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: attendance_reports attendance_reports_reporter_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attendance_reports
    ADD CONSTRAINT attendance_reports_reporter_id_foreign FOREIGN KEY (reporter_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: bgg_sync_logs bgg_sync_logs_game_system_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bgg_sync_logs
    ADD CONSTRAINT bgg_sync_logs_game_system_id_foreign FOREIGN KEY (game_system_id) REFERENCES public.game_systems(id) ON DELETE SET NULL;


--
-- Name: campaign_applications campaign_applications_campaign_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.campaign_applications
    ADD CONSTRAINT campaign_applications_campaign_id_foreign FOREIGN KEY (campaign_id) REFERENCES public.campaigns(id) ON DELETE CASCADE;


--
-- Name: campaign_applications campaign_applications_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.campaign_applications
    ADD CONSTRAINT campaign_applications_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: campaign_game_system campaign_game_system_campaign_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.campaign_game_system
    ADD CONSTRAINT campaign_game_system_campaign_id_foreign FOREIGN KEY (campaign_id) REFERENCES public.campaigns(id) ON DELETE CASCADE;


--
-- Name: campaign_game_system campaign_game_system_game_system_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.campaign_game_system
    ADD CONSTRAINT campaign_game_system_game_system_id_foreign FOREIGN KEY (game_system_id) REFERENCES public.game_systems(id) ON DELETE CASCADE;


--
-- Name: campaign_participants campaign_participants_campaign_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.campaign_participants
    ADD CONSTRAINT campaign_participants_campaign_id_foreign FOREIGN KEY (campaign_id) REFERENCES public.campaigns(id) ON DELETE CASCADE;


--
-- Name: campaign_participants campaign_participants_removed_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.campaign_participants
    ADD CONSTRAINT campaign_participants_removed_by_foreign FOREIGN KEY (removed_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: campaign_participants campaign_participants_short_link_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.campaign_participants
    ADD CONSTRAINT campaign_participants_short_link_id_foreign FOREIGN KEY (short_link_id) REFERENCES public.short_links(id) ON DELETE SET NULL;


--
-- Name: campaign_participants campaign_participants_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.campaign_participants
    ADD CONSTRAINT campaign_participants_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: campaigns campaigns_location_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.campaigns
    ADD CONSTRAINT campaigns_location_id_foreign FOREIGN KEY (location_id) REFERENCES public.locations(id) ON DELETE SET NULL;


--
-- Name: campaigns campaigns_owner_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.campaigns
    ADD CONSTRAINT campaigns_owner_id_foreign FOREIGN KEY (owner_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: escalated_agent_skill escalated_agent_skill_skill_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_agent_skill
    ADD CONSTRAINT escalated_agent_skill_skill_id_foreign FOREIGN KEY (skill_id) REFERENCES public.escalated_skills(id) ON DELETE CASCADE;


--
-- Name: escalated_article_categories escalated_article_categories_parent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_article_categories
    ADD CONSTRAINT escalated_article_categories_parent_id_foreign FOREIGN KEY (parent_id) REFERENCES public.escalated_article_categories(id) ON DELETE SET NULL;


--
-- Name: escalated_articles escalated_articles_category_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_articles
    ADD CONSTRAINT escalated_articles_category_id_foreign FOREIGN KEY (category_id) REFERENCES public.escalated_article_categories(id) ON DELETE SET NULL;


--
-- Name: escalated_chat_routing_rules escalated_chat_routing_rules_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_chat_routing_rules
    ADD CONSTRAINT escalated_chat_routing_rules_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.escalated_departments(id) ON DELETE SET NULL;


--
-- Name: escalated_chat_sessions escalated_chat_sessions_ticket_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_chat_sessions
    ADD CONSTRAINT escalated_chat_sessions_ticket_id_foreign FOREIGN KEY (ticket_id) REFERENCES public.escalated_tickets(id) ON DELETE CASCADE;


--
-- Name: escalated_custom_field_values escalated_custom_field_values_custom_field_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_custom_field_values
    ADD CONSTRAINT escalated_custom_field_values_custom_field_id_foreign FOREIGN KEY (custom_field_id) REFERENCES public.escalated_custom_fields(id) ON DELETE CASCADE;


--
-- Name: escalated_custom_object_records escalated_custom_object_records_object_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_custom_object_records
    ADD CONSTRAINT escalated_custom_object_records_object_id_foreign FOREIGN KEY (object_id) REFERENCES public.escalated_custom_objects(id) ON DELETE CASCADE;


--
-- Name: escalated_delayed_actions escalated_delayed_actions_ticket_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_delayed_actions
    ADD CONSTRAINT escalated_delayed_actions_ticket_id_foreign FOREIGN KEY (ticket_id) REFERENCES public.escalated_tickets(id) ON DELETE CASCADE;


--
-- Name: escalated_delayed_actions escalated_delayed_actions_workflow_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_delayed_actions
    ADD CONSTRAINT escalated_delayed_actions_workflow_id_foreign FOREIGN KEY (workflow_id) REFERENCES public.escalated_workflows(id) ON DELETE CASCADE;


--
-- Name: escalated_department_agent escalated_department_agent_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_department_agent
    ADD CONSTRAINT escalated_department_agent_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.escalated_departments(id) ON DELETE CASCADE;


--
-- Name: escalated_holidays escalated_holidays_schedule_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_holidays
    ADD CONSTRAINT escalated_holidays_schedule_id_foreign FOREIGN KEY (schedule_id) REFERENCES public.escalated_business_schedules(id) ON DELETE CASCADE;


--
-- Name: escalated_import_source_maps escalated_import_source_maps_import_job_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_import_source_maps
    ADD CONSTRAINT escalated_import_source_maps_import_job_id_foreign FOREIGN KEY (import_job_id) REFERENCES public.escalated_import_jobs(id) ON DELETE CASCADE;


--
-- Name: escalated_inbound_emails escalated_inbound_emails_reply_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_inbound_emails
    ADD CONSTRAINT escalated_inbound_emails_reply_id_foreign FOREIGN KEY (reply_id) REFERENCES public.escalated_replies(id) ON DELETE SET NULL;


--
-- Name: escalated_inbound_emails escalated_inbound_emails_ticket_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_inbound_emails
    ADD CONSTRAINT escalated_inbound_emails_ticket_id_foreign FOREIGN KEY (ticket_id) REFERENCES public.escalated_tickets(id) ON DELETE SET NULL;


--
-- Name: escalated_mentions escalated_mentions_reply_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_mentions
    ADD CONSTRAINT escalated_mentions_reply_id_foreign FOREIGN KEY (reply_id) REFERENCES public.escalated_replies(id) ON DELETE CASCADE;


--
-- Name: escalated_replies escalated_replies_ticket_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_replies
    ADD CONSTRAINT escalated_replies_ticket_id_foreign FOREIGN KEY (ticket_id) REFERENCES public.escalated_tickets(id) ON DELETE CASCADE;


--
-- Name: escalated_role_permission escalated_role_permission_permission_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_role_permission
    ADD CONSTRAINT escalated_role_permission_permission_id_foreign FOREIGN KEY (permission_id) REFERENCES public.escalated_permissions(id) ON DELETE CASCADE;


--
-- Name: escalated_role_permission escalated_role_permission_role_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_role_permission
    ADD CONSTRAINT escalated_role_permission_role_id_foreign FOREIGN KEY (role_id) REFERENCES public.escalated_roles(id) ON DELETE CASCADE;


--
-- Name: escalated_role_user escalated_role_user_role_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_role_user
    ADD CONSTRAINT escalated_role_user_role_id_foreign FOREIGN KEY (role_id) REFERENCES public.escalated_roles(id) ON DELETE CASCADE;


--
-- Name: escalated_satisfaction_ratings escalated_satisfaction_ratings_ticket_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_satisfaction_ratings
    ADD CONSTRAINT escalated_satisfaction_ratings_ticket_id_foreign FOREIGN KEY (ticket_id) REFERENCES public.escalated_tickets(id) ON DELETE CASCADE;


--
-- Name: escalated_side_conversation_replies escalated_side_conversation_replies_side_conversation_id_foreig; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_side_conversation_replies
    ADD CONSTRAINT escalated_side_conversation_replies_side_conversation_id_foreig FOREIGN KEY (side_conversation_id) REFERENCES public.escalated_side_conversations(id) ON DELETE CASCADE;


--
-- Name: escalated_side_conversations escalated_side_conversations_ticket_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_side_conversations
    ADD CONSTRAINT escalated_side_conversations_ticket_id_foreign FOREIGN KEY (ticket_id) REFERENCES public.escalated_tickets(id) ON DELETE CASCADE;


--
-- Name: escalated_ticket_activities escalated_ticket_activities_ticket_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_ticket_activities
    ADD CONSTRAINT escalated_ticket_activities_ticket_id_foreign FOREIGN KEY (ticket_id) REFERENCES public.escalated_tickets(id) ON DELETE CASCADE;


--
-- Name: escalated_ticket_followers escalated_ticket_followers_ticket_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_ticket_followers
    ADD CONSTRAINT escalated_ticket_followers_ticket_id_foreign FOREIGN KEY (ticket_id) REFERENCES public.escalated_tickets(id) ON DELETE CASCADE;


--
-- Name: escalated_ticket_links escalated_ticket_links_child_ticket_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_ticket_links
    ADD CONSTRAINT escalated_ticket_links_child_ticket_id_foreign FOREIGN KEY (child_ticket_id) REFERENCES public.escalated_tickets(id) ON DELETE CASCADE;


--
-- Name: escalated_ticket_links escalated_ticket_links_parent_ticket_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_ticket_links
    ADD CONSTRAINT escalated_ticket_links_parent_ticket_id_foreign FOREIGN KEY (parent_ticket_id) REFERENCES public.escalated_tickets(id) ON DELETE CASCADE;


--
-- Name: escalated_ticket_subjects escalated_ticket_subjects_ticket_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_ticket_subjects
    ADD CONSTRAINT escalated_ticket_subjects_ticket_id_foreign FOREIGN KEY (ticket_id) REFERENCES public.escalated_tickets(id) ON DELETE CASCADE;


--
-- Name: escalated_ticket_tag escalated_ticket_tag_tag_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_ticket_tag
    ADD CONSTRAINT escalated_ticket_tag_tag_id_foreign FOREIGN KEY (tag_id) REFERENCES public.escalated_tags(id) ON DELETE CASCADE;


--
-- Name: escalated_ticket_tag escalated_ticket_tag_ticket_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_ticket_tag
    ADD CONSTRAINT escalated_ticket_tag_ticket_id_foreign FOREIGN KEY (ticket_id) REFERENCES public.escalated_tickets(id) ON DELETE CASCADE;


--
-- Name: escalated_tickets escalated_tickets_contact_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_tickets
    ADD CONSTRAINT escalated_tickets_contact_id_foreign FOREIGN KEY (contact_id) REFERENCES public.escalated_contacts(id) ON DELETE SET NULL;


--
-- Name: escalated_tickets escalated_tickets_merged_into_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_tickets
    ADD CONSTRAINT escalated_tickets_merged_into_id_foreign FOREIGN KEY (merged_into_id) REFERENCES public.escalated_tickets(id) ON DELETE SET NULL;


--
-- Name: escalated_webhook_deliveries escalated_webhook_deliveries_webhook_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_webhook_deliveries
    ADD CONSTRAINT escalated_webhook_deliveries_webhook_id_foreign FOREIGN KEY (webhook_id) REFERENCES public.escalated_webhooks(id) ON DELETE CASCADE;


--
-- Name: escalated_workflow_logs escalated_workflow_logs_ticket_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_workflow_logs
    ADD CONSTRAINT escalated_workflow_logs_ticket_id_foreign FOREIGN KEY (ticket_id) REFERENCES public.escalated_tickets(id) ON DELETE CASCADE;


--
-- Name: escalated_workflow_logs escalated_workflow_logs_workflow_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.escalated_workflow_logs
    ADD CONSTRAINT escalated_workflow_logs_workflow_id_foreign FOREIGN KEY (workflow_id) REFERENCES public.escalated_workflows(id) ON DELETE CASCADE;


--
-- Name: event_announcements event_announcements_author_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_announcements
    ADD CONSTRAINT event_announcements_author_id_foreign FOREIGN KEY (author_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: event_announcements event_announcements_event_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_announcements
    ADD CONSTRAINT event_announcements_event_id_foreign FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: event_registrations event_registrations_event_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_registrations
    ADD CONSTRAINT event_registrations_event_id_foreign FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: event_registrations event_registrations_team_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_registrations
    ADD CONSTRAINT event_registrations_team_id_foreign FOREIGN KEY (team_id) REFERENCES public.teams(id) ON DELETE SET NULL;


--
-- Name: event_registrations event_registrations_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_registrations
    ADD CONSTRAINT event_registrations_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: events events_location_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.events
    ADD CONSTRAINT events_location_id_foreign FOREIGN KEY (location_id) REFERENCES public.locations(id) ON DELETE SET NULL;


--
-- Name: events events_organizer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.events
    ADD CONSTRAINT events_organizer_id_foreign FOREIGN KEY (organizer_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: game_applications game_applications_game_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_applications
    ADD CONSTRAINT game_applications_game_id_foreign FOREIGN KEY (game_id) REFERENCES public.games(id) ON DELETE CASCADE;


--
-- Name: game_applications game_applications_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_applications
    ADD CONSTRAINT game_applications_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: game_bulletins game_bulletins_game_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_bulletins
    ADD CONSTRAINT game_bulletins_game_id_foreign FOREIGN KEY (game_id) REFERENCES public.games(id) ON DELETE CASCADE;


--
-- Name: game_bulletins game_bulletins_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_bulletins
    ADD CONSTRAINT game_bulletins_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: game_game_system game_game_system_game_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_game_system
    ADD CONSTRAINT game_game_system_game_id_foreign FOREIGN KEY (game_id) REFERENCES public.games(id) ON DELETE CASCADE;


--
-- Name: game_game_system game_game_system_game_system_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_game_system
    ADD CONSTRAINT game_game_system_game_system_id_foreign FOREIGN KEY (game_system_id) REFERENCES public.game_systems(id) ON DELETE CASCADE;


--
-- Name: game_participants game_participants_attendance_reported_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_participants
    ADD CONSTRAINT game_participants_attendance_reported_by_foreign FOREIGN KEY (attendance_reported_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: game_participants game_participants_game_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_participants
    ADD CONSTRAINT game_participants_game_id_foreign FOREIGN KEY (game_id) REFERENCES public.games(id) ON DELETE CASCADE;


--
-- Name: game_participants game_participants_removed_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_participants
    ADD CONSTRAINT game_participants_removed_by_foreign FOREIGN KEY (removed_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: game_participants game_participants_short_link_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_participants
    ADD CONSTRAINT game_participants_short_link_id_foreign FOREIGN KEY (short_link_id) REFERENCES public.short_links(id) ON DELETE SET NULL;


--
-- Name: game_participants game_participants_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_participants
    ADD CONSTRAINT game_participants_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: game_system_category game_system_category_game_system_category_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_category
    ADD CONSTRAINT game_system_category_game_system_category_id_foreign FOREIGN KEY (game_system_category_id) REFERENCES public.game_system_categories(id) ON DELETE CASCADE;


--
-- Name: game_system_category game_system_category_game_system_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_category
    ADD CONSTRAINT game_system_category_game_system_id_foreign FOREIGN KEY (game_system_id) REFERENCES public.game_systems(id) ON DELETE CASCADE;


--
-- Name: game_system_category_relations game_system_category_relations_category_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_category_relations
    ADD CONSTRAINT game_system_category_relations_category_id_foreign FOREIGN KEY (category_id) REFERENCES public.game_system_categories(id) ON DELETE CASCADE;


--
-- Name: game_system_category_relations game_system_category_relations_related_category_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_category_relations
    ADD CONSTRAINT game_system_category_relations_related_category_id_foreign FOREIGN KEY (related_category_id) REFERENCES public.game_system_categories(id) ON DELETE CASCADE;


--
-- Name: game_system_designer game_system_designer_game_system_designer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_designer
    ADD CONSTRAINT game_system_designer_game_system_designer_id_foreign FOREIGN KEY (game_system_designer_id) REFERENCES public.game_system_designers(id) ON DELETE CASCADE;


--
-- Name: game_system_designer game_system_designer_game_system_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_designer
    ADD CONSTRAINT game_system_designer_game_system_id_foreign FOREIGN KEY (game_system_id) REFERENCES public.game_systems(id) ON DELETE CASCADE;


--
-- Name: game_system_family game_system_family_game_system_family_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_family
    ADD CONSTRAINT game_system_family_game_system_family_id_foreign FOREIGN KEY (game_system_family_id) REFERENCES public.game_system_families(id) ON DELETE CASCADE;


--
-- Name: game_system_family game_system_family_game_system_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_family
    ADD CONSTRAINT game_system_family_game_system_id_foreign FOREIGN KEY (game_system_id) REFERENCES public.game_systems(id) ON DELETE CASCADE;


--
-- Name: game_system_mechanic game_system_mechanic_game_system_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_mechanic
    ADD CONSTRAINT game_system_mechanic_game_system_id_foreign FOREIGN KEY (game_system_id) REFERENCES public.game_systems(id) ON DELETE CASCADE;


--
-- Name: game_system_mechanic game_system_mechanic_game_system_mechanic_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_mechanic
    ADD CONSTRAINT game_system_mechanic_game_system_mechanic_id_foreign FOREIGN KEY (game_system_mechanic_id) REFERENCES public.game_system_mechanics(id) ON DELETE CASCADE;


--
-- Name: game_system_mechanic_relations game_system_mechanic_relations_mechanic_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_mechanic_relations
    ADD CONSTRAINT game_system_mechanic_relations_mechanic_id_foreign FOREIGN KEY (mechanic_id) REFERENCES public.game_system_mechanics(id) ON DELETE CASCADE;


--
-- Name: game_system_mechanic_relations game_system_mechanic_relations_related_mechanic_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_mechanic_relations
    ADD CONSTRAINT game_system_mechanic_relations_related_mechanic_id_foreign FOREIGN KEY (related_mechanic_id) REFERENCES public.game_system_mechanics(id) ON DELETE CASCADE;


--
-- Name: game_system_publisher game_system_publisher_game_system_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_publisher
    ADD CONSTRAINT game_system_publisher_game_system_id_foreign FOREIGN KEY (game_system_id) REFERENCES public.game_systems(id) ON DELETE CASCADE;


--
-- Name: game_system_publisher game_system_publisher_game_system_publisher_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_system_publisher
    ADD CONSTRAINT game_system_publisher_game_system_publisher_id_foreign FOREIGN KEY (game_system_publisher_id) REFERENCES public.game_system_publishers(id) ON DELETE CASCADE;


--
-- Name: game_systems game_systems_base_game_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_systems
    ADD CONSTRAINT game_systems_base_game_id_foreign FOREIGN KEY (base_game_id) REFERENCES public.game_systems(id) ON DELETE SET NULL;


--
-- Name: games games_campaign_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.games
    ADD CONSTRAINT games_campaign_id_foreign FOREIGN KEY (campaign_id) REFERENCES public.campaigns(id) ON DELETE SET NULL;


--
-- Name: games games_location_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.games
    ADD CONSTRAINT games_location_id_foreign FOREIGN KEY (location_id) REFERENCES public.locations(id) ON DELETE SET NULL;


--
-- Name: games games_owner_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.games
    ADD CONSTRAINT games_owner_id_foreign FOREIGN KEY (owner_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: gm_profiles gm_profiles_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gm_profiles
    ADD CONSTRAINT gm_profiles_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: gm_social_links gm_social_links_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gm_social_links
    ADD CONSTRAINT gm_social_links_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: linked_accounts linked_accounts_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.linked_accounts
    ADD CONSTRAINT linked_accounts_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: local_subscriptions local_subscriptions_membership_type_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.local_subscriptions
    ADD CONSTRAINT local_subscriptions_membership_type_id_foreign FOREIGN KEY (membership_type_id) REFERENCES public.membership_types(id) ON DELETE CASCADE;


--
-- Name: local_subscriptions local_subscriptions_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.local_subscriptions
    ADD CONSTRAINT local_subscriptions_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: locations locations_managed_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.locations
    ADD CONSTRAINT locations_managed_by_foreign FOREIGN KEY (managed_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: model_has_permissions model_has_permissions_permission_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.model_has_permissions
    ADD CONSTRAINT model_has_permissions_permission_id_foreign FOREIGN KEY (permission_id) REFERENCES public.permissions(id) ON DELETE CASCADE;


--
-- Name: model_has_roles model_has_roles_role_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.model_has_roles
    ADD CONSTRAINT model_has_roles_role_id_foreign FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: nearby_discovery_views nearby_discovery_views_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nearby_discovery_views
    ADD CONSTRAINT nearby_discovery_views_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: push_subscriptions push_subscriptions_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.push_subscriptions
    ADD CONSTRAINT push_subscriptions_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: reviews reviews_gm_profile_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reviews
    ADD CONSTRAINT reviews_gm_profile_id_foreign FOREIGN KEY (gm_profile_id) REFERENCES public.gm_profiles(id) ON DELETE CASCADE;


--
-- Name: reviews reviews_reported_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reviews
    ADD CONSTRAINT reviews_reported_by_foreign FOREIGN KEY (reported_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: reviews reviews_reviewer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reviews
    ADD CONSTRAINT reviews_reviewer_id_foreign FOREIGN KEY (reviewer_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: role_has_permissions role_has_permissions_permission_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_has_permissions
    ADD CONSTRAINT role_has_permissions_permission_id_foreign FOREIGN KEY (permission_id) REFERENCES public.permissions(id) ON DELETE CASCADE;


--
-- Name: role_has_permissions role_has_permissions_role_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_has_permissions
    ADD CONSTRAINT role_has_permissions_role_id_foreign FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: session_debriefings session_debriefings_game_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.session_debriefings
    ADD CONSTRAINT session_debriefings_game_id_foreign FOREIGN KEY (game_id) REFERENCES public.games(id) ON DELETE CASCADE;


--
-- Name: session_debriefings session_debriefings_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.session_debriefings
    ADD CONSTRAINT session_debriefings_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: session_zero_confirmations session_zero_confirmations_session_zero_survey_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.session_zero_confirmations
    ADD CONSTRAINT session_zero_confirmations_session_zero_survey_id_foreign FOREIGN KEY (session_zero_survey_id) REFERENCES public.session_zero_surveys(id) ON DELETE CASCADE;


--
-- Name: session_zero_confirmations session_zero_confirmations_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.session_zero_confirmations
    ADD CONSTRAINT session_zero_confirmations_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: session_zero_surveys session_zero_surveys_game_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.session_zero_surveys
    ADD CONSTRAINT session_zero_surveys_game_id_foreign FOREIGN KEY (game_id) REFERENCES public.games(id) ON DELETE SET NULL;


--
-- Name: session_zero_surveys session_zero_surveys_gm_profile_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.session_zero_surveys
    ADD CONSTRAINT session_zero_surveys_gm_profile_id_foreign FOREIGN KEY (gm_profile_id) REFERENCES public.gm_profiles(id) ON DELETE CASCADE;


--
-- Name: short_link_hits short_link_hits_short_link_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.short_link_hits
    ADD CONSTRAINT short_link_hits_short_link_id_foreign FOREIGN KEY (short_link_id) REFERENCES public.short_links(id) ON DELETE CASCADE;


--
-- Name: short_links short_links_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.short_links
    ADD CONSTRAINT short_links_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: team_members team_members_invited_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.team_members
    ADD CONSTRAINT team_members_invited_by_foreign FOREIGN KEY (invited_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: team_members team_members_team_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.team_members
    ADD CONSTRAINT team_members_team_id_foreign FOREIGN KEY (team_id) REFERENCES public.teams(id) ON DELETE CASCADE;


--
-- Name: team_members team_members_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.team_members
    ADD CONSTRAINT team_members_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: teams teams_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teams
    ADD CONSTRAINT teams_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: user_app_visits user_app_visits_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_app_visits
    ADD CONSTRAINT user_app_visits_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: user_game_system_preferences user_game_system_preferences_game_system_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_game_system_preferences
    ADD CONSTRAINT user_game_system_preferences_game_system_id_foreign FOREIGN KEY (game_system_id) REFERENCES public.game_systems(id) ON DELETE CASCADE;


--
-- Name: user_game_system_preferences user_game_system_preferences_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_game_system_preferences
    ADD CONSTRAINT user_game_system_preferences_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: user_relationships user_relationships_related_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_relationships
    ADD CONSTRAINT user_relationships_related_user_id_foreign FOREIGN KEY (related_user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: user_relationships user_relationships_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_relationships
    ADD CONSTRAINT user_relationships_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: user_vibe_preferences user_vibe_preferences_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_vibe_preferences
    ADD CONSTRAINT user_vibe_preferences_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: users users_location_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_location_id_foreign FOREIGN KEY (location_id) REFERENCES public.locations(id) ON DELETE SET NULL;


--
-- PostgreSQL database dump complete
--

\unrestrict 1teZHlzvneGFulRIlu8NXBAVdiUgcrn9wamwkNbYsgYyn1cK5jCUDzhYDARPvk3

--
-- PostgreSQL database dump
--

\restrict h64FP9UnZ4NnOt79bJvuY8Y2esxLHgEejd9vxbhGRFxJ6nGI1a7kHliBeOlhkkB

-- Dumped from database version 17.10 (Debian 17.10-1.pgdg13+1)
-- Dumped by pg_dump version 18.4

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.migrations (id, migration, batch) FROM stdin;
1	0001_01_01_000000_create_users_table	1
2	0001_01_01_000001_create_cache_table	1
3	0001_01_01_000002_create_jobs_table	1
4	2026_04_12_175347_create_media_table	1
5	2026_04_12_175347_create_permission_tables	1
6	2026_04_12_180006_create_game_systems_tables	1
7	2026_04_12_180006_create_teams_tables	1
8	2026_04_12_180007_create_campaigns_tables	1
9	2026_04_12_180007_create_events_tables	1
10	2026_04_12_180007_create_membership_types_table	1
11	2026_04_12_180007_create_subscriptions_table	1
12	2026_04_12_180008_create_games_tables	1
13	2026_04_12_180013_alter_users_add_paddle_columns	1
14	2026_04_12_183439_create_linked_accounts_table	1
15	2026_04_13_000001_add_team_id_to_permission_tables	1
16	2026_04_13_122240_fix_users_trial_ends_at_column_type	1
17	2026_04_13_132449_add_performance_indexes	1
18	2026_04_13_193700_widen_linked_accounts_token_columns	1
19	2026_04_14_000001_create_translations_table	1
20	2026_04_14_000002_add_content_language_to_events_table	1
21	2026_04_14_082514_add_password_set_at_to_users_table	1
22	2026_04_15_100000_add_bgg_integration_to_game_systems	1
23	2026_04_15_102428_add_is_disabled_to_users_table	1
24	2026_04_15_120000_add_discovery_meta_to_games	1
25	2026_04_15_130000_add_can_create_public_entries_to_users	1
26	2026_04_15_130000_add_discovery_meta_to_campaigns	1
27	2026_04_15_180000_remove_location_from_campaigns	1
28	2026_04_16_000000_add_language_location_to_users_table	1
29	2026_04_16_000001_create_user_vibe_preferences_table	1
30	2026_04_16_154138_create_locations_table	1
31	2026_04_16_154210_add_location_id_to_games_events_users_tables	1
32	2026_04_16_155514_make_lat_lng_nullable_on_locations_table	1
33	2026_04_18_154350_create_user_relationships_table	1
34	2026_04_20_162127_add_geohash_4_to_locations_table	1
35	2026_04_20_210957_add_geohash_auto_trigger_to_locations_table	1
36	2026_04_21_091843_create_nearby_discovery_views_table	1
37	2026_04_21_180336_create_notifications_table	1
38	2026_04_21_180533_add_notification_settings_to_users_table	1
39	2026_04_22_075619_change_media_model_id_to_varchar	1
40	2026_04_22_090000_change_team_id_to_varchar_on_permission_tables	1
41	2026_04_22_090001_add_team_id_to_permission_table_unique_constraints	1
42	2026_04_22_160000_add_ttrpg_support_to_game_systems	1
43	2026_04_22_170000_add_descriptions_and_cross_links_to_taxonomy	1
44	2026_04_23_090150_add_platform_score_to_game_systems_table	1
45	2026_04_23_120524_create_gm_profiles_table	1
46	2026_04_23_131709_create_reviews_table	1
47	2026_04_23_140011_add_report_reason_to_reviews_table	1
48	2026_04_23_163914_create_session_zero_surveys_table	1
49	2026_04_23_163915_create_session_zero_confirmations_table	1
50	2026_04_23_192251_add_type_to_membership_types_table	1
51	2026_04_23_192346_create_local_subscriptions_table	1
52	2026_04_23_203643_add_game_type_to_games_table	1
53	2026_04_23_205643_create_activity_logs_table	1
54	2026_04_23_231358_convert_de_en_to_de_on_all_tables	1
55	2026_04_25_191002_add_location_id_to_campaigns_table	1
56	2026_04_27_060618_create_user_app_visits_table	1
57	2026_04_27_065158_create_push_subscriptions_table	1
58	2026_04_27_081048_add_reminder_sent_at_to_games_table	1
59	2026_04_28_100001_add_waitlisted_benched_to_participant_status	1
60	2026_04_28_100002_add_attendance_columns_to_game_participants	1
61	2026_04_28_100003_make_max_players_not_null_and_add_game_columns	1
62	2026_04_28_100004_add_waitlist_columns_to_game_participants	1
63	2026_04_28_100005_add_reliability_columns_to_users	1
64	2026_04_28_100006_create_attendance_reports_table	1
65	2026_04_28_132944_add_benched_at_to_game_participants	1
66	2026_04_28_160001_add_dispute_columns_to_attendance_tables	1
67	2026_04_28_170001_create_session_debriefings_table	1
68	2026_04_28_175428_add_reminder_24h_sent_at_to_games_table	1
69	2026_04_28_212359_add_created_at_to_game_participants	1
70	2026_04_28_222250_update_unique_constraint_on_push_subscriptions	1
71	2026_04_29_093000_add_confirmation_attempts_to_game_participants	1
72	2026_04_29_100001_change_attendance_dispute_reason_to_text	1
73	2026_04_29_100002_add_cancelled_early_to_attendance_status	1
74	2026_04_29_100003_change_attendance_reports_dispute_reason_to_text	1
75	2026_05_02_091305_migrate_locations_to_uuid	1
76	2026_05_02_094000_migrate_game_systems_to_uuid	1
77	2026_05_02_110000_migrate_teams_to_uuid	1
78	2026_05_02_130000_migrate_users_to_uuid	1
79	2026_05_02_140000_fix_model_has_permissions_unique_constraint	1
80	2026_05_02_150000_migrate_bgg_taxonomy_tables_to_uuid	1
81	2026_05_02_160000_migrate_auxiliary_tables_to_uuid	1
82	2026_05_02_170000_migrate_remaining_tables_to_uuid	1
83	2026_05_07_034537_create_personal_access_tokens_table	1
84	2026_05_08_000001_add_share_token_to_games	1
85	2026_05_08_000002_add_share_token_to_campaigns	1
86	2026_05_08_000003_add_join_source_to_game_participants	1
87	2026_05_08_000004_add_join_source_to_campaign_participants	1
88	2026_05_08_000005_add_share_token_indexes	1
89	2026_05_11_213832_add_bio_and_slug_to_users_table	1
90	2026_05_12_112154_create_seo_table	1
91	2026_05_14_000000_drop_contact_messages_table	1
92	2026_05_14_112713_drop_game_system_requests_table	1
93	2026_05_14_124508_add_event_type_index_to_activity_logs_table	1
94	2026_05_14_232543_create_escalated_departments_table	1
95	2026_05_14_232544_create_escalated_sla_policies_table	1
96	2026_05_14_232545_create_escalated_tickets_table	1
97	2026_05_14_232546_create_escalated_replies_table	1
98	2026_05_14_232547_create_escalated_attachments_table	1
99	2026_05_14_232548_create_escalated_tags_table	1
100	2026_05_14_232549_create_escalated_ticket_activities_table	1
101	2026_05_14_232550_create_escalated_escalation_rules_table	1
102	2026_05_14_232551_create_escalated_canned_responses_table	1
103	2026_05_14_232552_create_escalated_settings_table	1
104	2026_05_14_232553_add_guest_fields_to_escalated_tickets_table	1
105	2026_05_14_232554_create_escalated_inbound_emails_table	1
106	2026_05_14_232555_create_escalated_macros_table	1
107	2026_05_14_232556_create_escalated_ticket_followers_table	1
108	2026_05_14_232557_create_escalated_satisfaction_ratings_table	1
109	2026_05_14_232558_add_is_pinned_to_escalated_replies_table	1
110	2026_05_14_232559_create_escalated_api_tokens_table	1
111	2026_05_14_232600_create_escalated_plugins_table	1
112	2026_05_14_232601_create_escalated_custom_fields_table	1
113	2026_05_14_232602_create_escalated_ticket_statuses_table	1
114	2026_05_14_232603_create_escalated_business_schedules_table	1
115	2026_05_14_232604_create_escalated_holidays_table	1
116	2026_05_14_232605_create_escalated_roles_tables	1
117	2026_05_14_232606_create_escalated_audit_logs_table	1
118	2026_05_14_232607_add_merged_into_to_escalated_tickets	1
119	2026_05_14_232608_create_escalated_ticket_links_table	1
120	2026_05_14_232609_create_escalated_side_conversations_table	1
121	2026_05_14_232610_create_escalated_agent_profiles_table	1
122	2026_05_14_232611_create_escalated_skills_table	1
123	2026_05_14_232612_create_escalated_agent_capacity_table	1
124	2026_05_14_232613_create_escalated_webhooks_table	1
125	2026_05_14_232614_create_escalated_automations_table	1
126	2026_05_14_232615_add_category_to_escalated_escalation_rules	1
127	2026_05_14_232616_create_escalated_knowledge_base_tables	1
128	2026_05_14_232617_add_two_factor_columns	1
129	2026_05_14_232618_add_conditions_to_escalated_custom_fields	1
130	2026_05_14_232619_create_escalated_custom_objects_tables	1
131	2026_05_14_232620_create_escalated_import_jobs_table	1
132	2026_05_14_232621_create_escalated_import_source_maps_table	1
133	2026_05_14_232622_create_escalated_plugin_store_table	1
134	2026_05_14_232623_add_ticket_type_to_escalated_tickets	1
135	2026_05_14_232624_add_security_indexes_to_escalated_tickets	1
136	2026_05_14_232625_add_email_branding_settings	1
137	2026_05_14_232626_add_snooze_columns_to_escalated_tickets	1
138	2026_05_14_232627_create_escalated_saved_views_table	1
139	2026_05_14_232628_add_chat_columns_to_escalated_tickets_table	1
140	2026_05_14_232629_create_escalated_chat_sessions_table	1
141	2026_05_14_232630_create_escalated_chat_routing_rules_table	1
142	2026_05_14_232631_add_chat_status_to_escalated_agent_profiles_table	1
143	2026_05_14_232632_create_escalated_mentions_table	1
144	2026_05_14_232633_create_escalated_workflows_table	1
145	2026_05_14_232634_create_escalated_workflow_logs_table	1
146	2026_05_14_232635_create_escalated_delayed_actions_table	1
147	2026_05_14_232636_create_escalated_contacts_table	1
148	2026_05_14_232637_backfill_contact_ids_on_tickets	1
149	2026_05_14_233000_convert_escalated_user_refs_to_uuid	1
150	2026_05_15_155739_create_gm_social_links_table	1
151	2026_05_16_100000_create_short_links_tables	1
152	2026_05_16_200000_add_short_link_id_to_game_participants	1
153	2026_05_16_300000_add_short_link_id_to_campaign_participants	1
154	2026_05_16_500000_add_max_links_per_entity_to_users	1
155	2026_05_17_100000_add_referer_domain_to_short_link_hits	1
156	2026_05_17_105933_encrypt_linked_account_tokens	1
157	2026_05_17_150147_add_varchar_to_uuid_cast	1
158	2026_05_17_200434_add_anonymized_at_to_users_table	1
159	2026_05_17_213502_add_gender_consent_to_users_table	1
160	2026_05_17_233024_create_suppressed_invite_emails_table	1
161	2026_05_18_100000_add_policy_acceptance_to_users_table	1
162	2026_05_18_110000_add_analytics_consent_to_users_table	1
163	2026_05_24_070049_consolidate_notification_types	1
164	2026_05_30_111644_backfill_owner_participants	1
165	2026_05_31_100000_add_removed_at_to_participants	1
166	2026_06_06_100000_add_venue_profile_to_locations_table	1
167	2026_06_06_110000_add_location_instructions_to_games_and_campaigns_tables	1
168	2026_06_08_142711_change_notifications_data_to_jsonb	1
169	2026_06_15_100000_add_slug_to_locations_table	1
170	2026_06_16_090000_backfill_slug_for_managed_commercial_venues	1
171	2026_06_16_100000_add_drift_columns_to_locations_table	1
172	2026_06_16_100100_add_venue_review_support	1
173	2026_06_27_000001_create_escalated_ticket_subjects_table	1
174	2026_07_11_100000_add_invitee_email_to_participants	1
175	2026_07_11_200000_add_email_invite_to_join_source_check	1
176	2026_07_12_100000_add_benched_at_to_campaign_participants	1
177	2026_07_13_100000_add_bench_mode_to_games_and_campaigns	1
178	2026_07_13_100001_add_waitlist_columns_to_campaign_participants	1
179	2026_07_13_100002_add_created_at_to_campaign_participants	1
180	2026_07_14_100000_change_seo_model_id_to_uuid	1
181	2026_07_17_100000_add_short_link_to_join_source_check	1
182	2026_07_18_100000_convert_to_spatie_translatable	1
183	2026_07_18_200000_add_jsonb_trgm_indexes	1
184	2026_07_22_100000_create_game_bulletins_table	1
185	2026_07_23_100000_add_attendance_window_columns_to_games_table	1
186	2026_07_23_110000_update_attendance_tables_for_consensus	1
187	2026_07_25_100000_add_game_systems_to_games_table	1
188	2026_07_26_100000_add_host_note_to_games_table	1
189	2026_07_26_100000_convert_game_systems_to_jsonb_add_gin_index	1
190	2026_07_27_100000_add_approved_at_to_game_participants	1
191	2026_07_27_110000_add_promoted_manually_to_game_participants	1
192	2026_07_28_100000_add_game_type_to_campaigns_table	1
193	2026_07_29_100000_create_game_game_system_pivot_table	1
194	2026_07_29_110000_create_campaign_game_system_pivot_table	1
195	2026_07_30_100000_drop_legacy_game_system_columns	1
196	2026_07_31_100000_drop_campaign_images_column	1
197	2026_08_01_100000_drop_stale_notification_settings_default	1
198	2026_08_01_110000_add_weekly_digest_enabled_to_users_table	1
199	2026_08_02_100000_add_host_note_to_campaigns_table	1
\.


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.migrations_id_seq', 199, true);


--
-- PostgreSQL database dump complete
--

\unrestrict h64FP9UnZ4NnOt79bJvuY8Y2esxLHgEejd9vxbhGRFxJ6nGI1a7kHliBeOlhkkB

